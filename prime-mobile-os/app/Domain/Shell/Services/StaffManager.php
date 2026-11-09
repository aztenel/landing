<?php

namespace App\Domain\Shell\Services;

use App\Domain\Shell\Enums\DeviceStatus;
use App\Domain\Shell\Models\Device;
use App\Domain\Shell\Models\Invitation;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Limit;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Phone\Phone;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff of the firm (TZ 03 §14–15, TZ 04 Э25.1): invite, change, block, dismiss, restore, reset password.
 * Every method checks the right of the actor on the server and writes the action journal.
 *
 * Other modules add checks shown before a dismissal (open shift, orders with prepayment…):
 *   StaffManager::addDismissalCheck(fn (Membership $m): ?string => $hasOpenShift ? __('Открыта смена…') : null);
 */
class StaffManager
{
    /** @var list<Closure(Membership): ?string> */
    private static array $dismissalChecks = [];

    public function __construct(private readonly AccessChecker $checker) {}

    public static function addDismissalCheck(Closure $check): void
    {
        self::$dismissalChecks[] = $check;
    }

    /** @return list<string> warnings to confirm before dismissing (none forbids the dismissal, TZ 03 §15.2) */
    public function dismissalWarnings(Membership $membership): array
    {
        return array_values(array_filter(array_map(fn (Closure $c) => $c($membership), self::$dismissalChecks)));
    }

    /**
     * Invite a person (TZ 03 §14). Existing user (other firm) keeps the password; a dismissed member of this
     * firm must be restored instead.
     *
     * @param  array{name: string, phone: string, role_id: int, store_ids: list<int>, store_flags?: array, limits?: array, payroll?: array, started_on?: ?string}  $data
     * @return array{membership: Membership, url: string}
     */
    public function invite(array $data, User $by): array
    {
        Access::authorize('staff.manage', __('Приглашать сотрудников может владелец'), user: $by);
        $phone = $this->phone($data['phone']);
        $name = trim($data['name']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            throw UserFacingException::because('name', __('Имя и фамилия — от 2 до 60 символов'));
        }
        $role = $this->role((int) $data['role_id'], $by);
        $storeIds = $this->stores($data['store_ids'] ?? []);

        return DB::transaction(function () use ($data, $phone, $name, $role, $storeIds, $by) {
            $user = User::query()->where('phone', $phone)->first();
            if ($user) {
                $existing = Membership::query()->where('user_id', $user->id)->first();
                if ($existing && $existing->status === MembershipStatus::Dismissed) {
                    throw UserFacingException::because('dismissed', __('Этот человек уволен из фирмы. Восстановите его в списке сотрудников — история продаж та же'));
                }
                if ($existing) {
                    throw UserFacingException::because('exists', __('Этот сотрудник уже есть в списке'));
                }
            } else {
                $user = new User;
                $user->forceFill(['phone' => $phone, 'name' => $name, 'password' => Hash::make(Str::random(40))])->save();
            }

            $membership = new Membership;
            $membership->forceFill([
                'user_id' => $user->id,
                'status' => MembershipStatus::Invited,
                'store_ids' => $storeIds,
                'store_flags' => $this->flags($storeIds, $data['store_flags'] ?? []),
                'limits' => $this->limits($data['limits'] ?? []) ?: new \stdClass,
                'payroll' => $this->payroll($data['payroll'] ?? []) ?: new \stdClass,
                'position' => $role->title,
                'started_on' => $data['started_on'] ?? null,
                'invited_at' => now(),
                'created_by' => $by->id,
            ])->save();
            $this->assignRole($user, $role);
            $url = $this->issueLink($membership, Invitation::INVITE, $by);
            AuditLog::record('settings', 'member_invited', $membership, ['name' => $name, 'role' => $role->title], $by);

            return ['membership' => $membership, 'url' => $url];
        });
    }

    /** @param array{name?: string, role_id?: int, store_ids?: list<int>, store_flags?: array, limits?: array, payroll?: array} $data */
    public function update(Membership $membership, array $data, User $by): Membership
    {
        Access::authorize('staff.manage', __('Менять сотрудников может владелец'), user: $by);
        $user = $membership->user;
        if ($user->is($by) && isset($data['role_id']) && $this->roleOf($user)?->id !== (int) $data['role_id']) {
            throw UserFacingException::because('own_role', __('Свою роль менять нельзя'));
        }

        return DB::transaction(function () use ($membership, $data, $by, $user) {
            $old = $this->snapshot($membership);
            if (isset($data['role_id'])) {
                $role = $this->role((int) $data['role_id'], $by);
                $current = $this->roleOf($user);
                if ($current?->is_owner && ! $role->is_owner) {
                    $this->assertNotLastOwner($membership);
                }
                $this->assignRole($user, $role);
                $membership->position = $role->title;
            }
            if (isset($data['name']) && trim($data['name']) !== '') {
                $user->forceFill(['name' => trim($data['name'])])->save();
            }
            if (array_key_exists('store_ids', $data)) {
                $storeIds = $this->stores($data['store_ids']);
                $membership->store_ids = $storeIds;
                $membership->store_flags = $this->flags($storeIds, $data['store_flags'] ?? []);
            }
            if (array_key_exists('limits', $data)) {
                $membership->limits = $this->limits($data['limits']) ?: new \stdClass;
            }
            if (array_key_exists('payroll', $data)) {
                $membership->payroll = $this->payroll($data['payroll']) ?: new \stdClass;
            }
            $membership->save();
            Access::instance()->flush();
            AuditLog::record('settings', 'member_updated', $membership, [
                'name' => $user->name, 'old' => $old, 'new' => $this->snapshot($membership->refresh()),
            ], $by);

            return $membership;
        });
    }

    public function block(Membership $membership, ?string $reason, User $by): Membership
    {
        Access::authorize('staff.dismiss', __('Блокирует сотрудников владелец'), user: $by);
        $this->assertNotSelf($membership, $by);
        $this->assertNotLastOwner($membership);
        $membership->forceFill(['status' => MembershipStatus::Blocked, 'blocked_reason' => $reason, 'blocked_at' => now()])->save();
        $this->closeSessions($membership->user);
        $this->revokeLinks($membership);
        AuditLog::record('settings', 'member_blocked', $membership, ['name' => $membership->user->name, 'reason' => $reason], $by);

        return $membership;
    }

    public function unblock(Membership $membership, User $by): Membership
    {
        Access::authorize('staff.dismiss', __('Разблокирует сотрудников владелец'), user: $by);
        $membership->forceFill(['status' => MembershipStatus::Active, 'blocked_reason' => null, 'blocked_at' => null])->save();
        AuditLog::record('settings', 'member_unblocked', $membership, ['name' => $membership->user->name], $by);

        return $membership;
    }

    public function dismiss(Membership $membership, ?string $reason, User $by): Membership
    {
        Access::authorize('staff.dismiss', __('Увольняет сотрудников владелец'), user: $by);
        $this->assertNotSelf($membership, $by);
        $this->assertNotLastOwner($membership);

        return DB::transaction(function () use ($membership, $reason, $by) {
            $membership->forceFill(['status' => MembershipStatus::Dismissed, 'dismissed_at' => now(), 'dismissal_reason' => $reason])->save();
            $this->closeSessions($membership->user);
            $this->revokeLinks($membership);
            // Personal devices of the person are switched off (TZ 03 §15.2 p.4).
            Device::query()->where('owner_user_id', $membership->user_id)->where('status', '!=', DeviceStatus::Disabled->value)
                ->get()->each(fn (Device $d) => $d->forceFill(['status' => DeviceStatus::Disabled->value, 'disabled_by' => $by->id, 'disabled_at' => now()])->save());
            AuditLog::record('settings', 'member_dismissed', $membership, ['name' => $membership->user->name, 'reason' => $reason], $by);

            return $membership;
        });
    }

    /** «Восстановить»: active again with the same role and stores; devices must be allowed again (TZ 03 §15.2). */
    public function restore(Membership $membership, User $by): Membership
    {
        Access::authorize('staff.manage', __('Восстанавливает сотрудников владелец'), user: $by);
        $membership->forceFill(['status' => MembershipStatus::Active, 'dismissed_at' => null, 'dismissal_reason' => null])->save();
        AuditLog::record('settings', 'member_restored', $membership, ['name' => $membership->user->name], $by);

        return $membership;
    }

    /** New one-time link (invitation again or password reset, TZ 03 §7.5) — old links stop working. */
    public function passwordLink(Membership $membership, User $by): string
    {
        Access::authorize('staff.manage', __('Пароль сбрасывает владелец'), user: $by);
        $kind = $membership->status === MembershipStatus::Invited ? Invitation::INVITE : Invitation::PASSWORD_RESET;
        $url = $this->issueLink($membership, $kind, $by);
        AuditLog::record('settings', 'password_reset_link', $membership, ['name' => $membership->user->name], $by);

        return $url;
    }

    /** «Выйти везде» for a person: the owner for anybody, the person — for himself (TZ 03 §8.4). */
    public function logoutEverywhere(Membership $membership, User $by): void
    {
        if (! $membership->user->is($by)) {
            Access::authorize('staff.manage', __('Закрыть сессии другого сотрудника может владелец'), user: $by);
        }
        $this->closeSessions($membership->user);
        AuditLog::record('settings', 'sessions_closed', $membership, ['name' => $membership->user->name], $by);
    }

    /** Open link: the invitation of this firm by its token (null = unknown / expired / used). */
    public static function findLink(string $token): ?Invitation
    {
        return Invitation::query()->withoutGlobalScopes()->where('token_hash', Invitation::hashToken($token))->first();
    }

    /**
     * Accept an invitation / set a new password by the link (TZ 03 §14.2, Э1.2). Activates the membership.
     */
    public function accept(Invitation $invitation, string $password, bool $rulesAccepted, ?int $rulesVersion = null): User
    {
        if (! $invitation->isUsable()) {
            throw UserFacingException::because('link_expired', __('Приглашение устарело. Попросите владельца отправить новое'));
        }
        $user = $invitation->user;
        PasswordRules::assert($password, $user->phone);
        if (! $rulesAccepted) {
            throw UserFacingException::because('rules', __('Подтвердите, что прочитали правила работы с данными клиентов'));
        }

        return CurrentOrganization::instance()->run($invitation->organization_id, function () use ($invitation, $password, $rulesVersion, $user) {
            return DB::transaction(function () use ($invitation, $password, $rulesVersion, $user) {
                $user->forceFill(['password' => Hash::make($password), 'must_change_password' => false, 'password_changed_at' => now()])->save();
                $membership = $invitation->membership;
                $attrs = ['rules_accepted_at' => now(), 'rules_version' => $rulesVersion];
                if ($membership->status === MembershipStatus::Invited) {
                    $attrs += ['status' => MembershipStatus::Active, 'accepted_at' => now()];
                }
                $membership->forceFill($attrs)->save();
                $invitation->forceFill(['used_at' => now()])->save();
                Invitation::query()->where('membership_id', $membership->id)->whereNull('used_at')->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
                $this->closeSessions($user);
                AuditLog::record('auth', $invitation->kind === Invitation::INVITE ? 'invitation_accepted' : 'password_changed', $membership, [], $user);

                return $user;
            });
        });
    }

    public function roleOf(User $user): ?Role
    {
        $user->unsetRelation('roles');

        /** @var Role|null */
        return $user->roles()->first();
    }

    public function closeSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    private function issueLink(Membership $membership, string $kind, User $by): string
    {
        $this->revokeLinks($membership);
        $token = Str::random(48);
        $hours = $kind === Invitation::INVITE ? (int) config('prime.invitation_hours', 72) : (int) config('prime.password_reset_hours', 24);
        $link = new Invitation;
        $link->forceFill([
            'membership_id' => $membership->id,
            'user_id' => $membership->user_id,
            'kind' => $kind,
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addHours($hours),
            'created_by' => $by->id,
        ])->save();

        return route('erp.invitation', ['token' => $token]);
    }

    private function revokeLinks(Membership $membership): void
    {
        Invitation::query()->where('membership_id', $membership->id)->whereNull('used_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function assignRole(User $user, Role $role): void
    {
        $user->unsetRelation('roles');
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Access::instance()->flush();
    }

    /** The role must be of this firm; only an owner hands out the owner role (TZ 03 §2 p.2). */
    private function role(int $roleId, User $by): Role
    {
        $role = Role::query()->where('organization_id', CurrentOrganization::instance()->requireId())->whereKey($roleId)->first();
        if (! $role) {
            throw UserFacingException::because('role', __('Выберите роль'));
        }
        if ($role->is_owner && ! Access::isOwner($by)) {
            throw AccessDenied::because('access_denied', __('Роль «Владелец» назначает только владелец'));
        }

        return $role;
    }

    /** @return list<int> */
    private function stores(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        if ($ids === []) {
            throw UserFacingException::because('stores', __('Отметьте хотя бы один магазин'));
        }
        $found = Store::query()->whereKey($ids)->pluck('id')->all();
        if (count($found) !== count($ids)) {
            throw UserFacingException::because('stores', __('Магазин не найден в этой фирме'));
        }

        return $ids;
    }

    private function flags(array $storeIds, array $flags): array
    {
        $out = [];
        foreach ($storeIds as $id) {
            $f = $flags[$id] ?? $flags[(string) $id] ?? [];
            $out[(string) $id] = ['sales' => (bool) ($f['sales'] ?? true), 'finance' => (bool) ($f['finance'] ?? true)];
        }

        return $out;
    }

    /** Personal limits: only known codes; '' = remove (as the role), 'inf' = ∞ (null). */
    private function limits(array $limits): array
    {
        $out = [];
        foreach ($limits as $code => $value) {
            $limit = Limit::tryFrom((string) $code);
            if (! $limit || $value === '' || $value === null) {
                continue;
            }
            $out[$limit->value] = $value === 'inf' ? null : max(0, (int) $value);
        }

        return $out;
    }

    private function payroll(array $payroll): array
    {
        $out = [];
        foreach (['phones', 'accessories'] as $key) {
            $v = $payroll[$key] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $v = str_replace(',', '.', (string) $v);
            if (! is_numeric($v) || (float) $v < 0 || (float) $v > 50) {
                throw UserFacingException::because('payroll', __('Процент продавцу — от 0 до 50 %'));
            }
            $out[$key] = number_format((float) $v, 2, '.', '');
        }

        return $out;
    }

    private function phone(string $raw): string
    {
        try {
            return Phone::normalize($raw);
        } catch (InvalidArgumentException) {
            throw UserFacingException::because('phone', __('Введите номер полностью: 10 цифр после +7'));
        }
    }

    private function assertNotSelf(Membership $membership, User $by): void
    {
        if ($membership->user_id === $by->id) {
            throw UserFacingException::because('self', __('Себя заблокировать или уволить нельзя'));
        }
    }

    /** There is always at least one active owner (TZ 03 §2 p.1). */
    private function assertNotLastOwner(Membership $membership): void
    {
        $role = $this->roleOf($membership->user);
        if (! $role?->is_owner) {
            return;
        }
        $owners = Membership::query()->where('status', MembershipStatus::Active->value)->where('id', '!=', $membership->id)->get()
            ->filter(fn (Membership $m) => (bool) $this->roleOf($m->user)?->is_owner);
        if ($owners->isEmpty()) {
            throw UserFacingException::because('last_owner', __('Это последний владелец фирмы — его нельзя заблокировать, уволить или сменить ему роль'));
        }
    }

    private function snapshot(Membership $m): array
    {
        return [
            'role' => $this->roleOf($m->user)?->title,
            'stores' => $m->store_ids,
            'limits' => $m->limits,
            'payroll' => $m->payroll,
        ];
    }
}
