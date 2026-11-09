<?php

namespace App\Domain\Shell\Services;

use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Limit;
use App\Domain\Tenancy\Access\PermissionCatalog;
use App\Domain\Tenancy\Access\RoleTemplates;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\RoleLimit;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles of the firm with the permission matrix and limits (TZ 03 §16, TZ 04 Э25.2).
 *
 * State of a permission in a role: off · on · «только заявкой» (role_limits[perm] = 0).
 * Limits L1…L18 are shown under the permission they limit (LIMIT_OF); empty = ∞, 0 = always approval.
 */
class RoleEditor
{
    /** Which permission a limit belongs to (for the matrix screen). */
    public const LIMIT_OF = [
        'L1' => 'sale.discount', 'L2' => 'sale.discount', 'L3' => 'sale.discount',
        'L4' => 'sale.credit', 'L5' => 'sale.credit', 'L6' => 'sale.credit',
        'L7' => 'return.accessory', 'L8' => 'return.phone', 'L9' => 'return.phone',
        'L10' => 'expense.create', 'L11' => 'expense.create',
        'L12' => 'tradein.buyout', 'L13' => 'tradein.over_grid', 'L14' => 'price.edit',
        'L15' => 'stock.write_off', 'L16' => 'money.adjust', 'L17' => 'bonus.manual', 'L18' => 'doc.reverse',
    ];

    /** One line in plain words under a permission (TZ 04 Э25.2). */
    public const HINTS = [
        'sale.create' => 'Касса: скан, корзина, оплата, чек',
        'sale.discount' => 'Сверх лимита сотрудник отправит заявку вам на телефон',
        'sale.below_cost' => 'Цена ниже себестоимости — только с вашего разрешения',
        'sale.credit' => 'Общий долг клиента после продажи не больше лимита',
        'sale.no_imei_check' => 'Закон запрещает продавать телефон без отметки госбазы',
        'return.phone' => 'Возврат денег покупателю за телефон',
        'stock.receive' => 'Приход телефонов сканером IMEI',
        'stock.cost_edit' => 'Цена закупа в приходе',
        'stock.write_off' => 'Сверх лимита — заявка',
        'price.edit' => 'Изменение больше лимита — заявка',
        'expense.create' => 'Расход из кассы магазина',
        'rate.set' => 'Курс $ для всех операций фирмы',
        'doc.reverse' => 'Отмена проведённого документа обратными записями',
        'doc.backdate' => 'До 3 дней назад, с причиной, не в закрытом месяце',
        'approval.decide' => 'Только в пределах собственных лимитов',
        'staff.manage' => 'Только владелец',
        'role.manage' => 'Только владелец',
        'audit.view' => 'Кто, что и когда сделал',
        'stock.view_cost' => 'Без этого сотрудник не увидит себестоимость нигде: ни в складе, ни в отчётах, ни в Excel',
        'stock.view_supplier' => 'Поставщик, курс и валюта закупа телефона',
        'profit.view' => 'Прибыль и наценка в чеках, отчётах и на статистике',
        'customer.view_iin' => 'Без этого — только последние 4 цифры',
        'money.view_bank_balances' => 'Остатки Kaspi, банка и терминала',
        'customer.export' => 'Выгрузка базы клиентов в Excel',
        'report.export' => 'Выгрузка отчётов в Excel',
    ];

    /**
     * @return list<array{key: string, title: string, items: list<array{code: string, title: string, hint: ?string, on: bool, approval: bool, limits: list<array{code: string, title: string, unit: string, value: ?int}>}>}>
     */
    public function matrix(Role $role): array
    {
        $granted = $role->permissions()->pluck('name')->flip();
        $limits = RoleLimit::query()->where('role_id', $role->id)->pluck('value', 'code');
        $groups = [];
        foreach (PermissionCatalog::GROUPS as $key => $title) {
            $groups[$key] = ['key' => $key, 'title' => $title, 'items' => []];
        }
        foreach (PermissionCatalog::MATRIX as $code => $row) {
            $limitRows = [];
            foreach (self::LIMIT_OF as $lCode => $perm) {
                if ($perm === $code) {
                    $limit = Limit::from($lCode);
                    $limitRows[] = [
                        'code' => $lCode, 'title' => $limit->title(), 'unit' => $limit->unit(),
                        'value' => $limits->has($lCode) ? $limits[$lCode] : null,
                    ];
                }
            }
            $groups[$row[0]]['items'][] = [
                'code' => $code,
                'title' => $row[1],
                'hint' => self::HINTS[$code] ?? null,
                'on' => $role->is_owner || $granted->has($code),
                'approval' => ! $role->is_owner && $limits->has($code) && (int) $limits[$code] === 0,
                'limits' => $limitRows,
            ];
        }

        return array_values($groups);
    }

    /**
     * Save switches and limits of a role. $state: [code => ['on' => bool, 'approval' => bool]],
     * $limitValues: ['L1' => int|null] in storage units (tiyn, percent ×100, days).
     *
     * @return int how many members got the change
     */
    public function save(Role $role, array $state, array $limitValues, User $by): int
    {
        Access::authorize('role.manage', __('Роли меняет только владелец'), user: $by);
        $this->assertOwnFirm($role);
        if ($role->is_owner) {
            throw UserFacingException::because('owner_role', __('Роль «Владелец» не редактируется: у неё все права без лимитов'));
        }
        if ($this->roleOfUser($by)?->is($role)) {
            throw UserFacingException::because('own_role', __('Свою роль менять нельзя'));
        }
        $byIsOwner = Access::isOwner($by);
        $granted = [];
        $approvalOnly = [];
        foreach (PermissionCatalog::codes() as $code) {
            $s = $state[$code] ?? ['on' => false];
            if (! ($s['on'] ?? false)) {
                continue;
            }
            // Nobody hands out a right he does not have himself (TZ 03 §16.2 p.3).
            if (! $byIsOwner && ! Access::sees($code, $by)) {
                throw AccessDenied::because('access_denied', __('Нельзя выдать право, которого нет у вас: :p', ['p' => PermissionCatalog::title($code)]));
            }
            $granted[] = $code;
            if ($s['approval'] ?? false) {
                $approvalOnly[] = $code;
            }
        }

        return DB::transaction(function () use ($role, $granted, $approvalOnly, $limitValues, $by) {
            $before = $this->summary($role);
            $role->syncPermissions($granted);
            RoleLimit::query()->where('role_id', $role->id)->delete();
            $rows = [];
            $now = now();
            foreach (Limit::cases() as $limit) {
                $value = array_key_exists($limit->value, $limitValues) ? $limitValues[$limit->value] : null;
                $rows[] = ['organization_id' => $role->organization_id, 'role_id' => $role->id, 'code' => $limit->value,
                    'value' => $value === null ? null : max(0, (int) $value), 'created_at' => $now, 'updated_at' => $now];
            }
            foreach ($approvalOnly as $code) {
                $rows[] = ['organization_id' => $role->organization_id, 'role_id' => $role->id, 'code' => $code,
                    'value' => 0, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('role_limits')->insert($rows);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Access::instance()->flush();
            $after = $this->summary($role->refresh());
            AuditLog::record('settings', 'role_saved', $role, [
                'role' => $role->title,
                'added' => array_values(array_diff($after['permissions'], $before['permissions'])),
                'removed' => array_values(array_diff($before['permissions'], $after['permissions'])),
                'old' => $before['limits'], 'new' => $after['limits'],
            ], $by);

            return $this->membersCount($role);
        });
    }

    /** «Новая роль» on the basis of an existing one (empty roles are not created, TZ 03 §16.2 p.1). */
    public function create(string $title, Role $basedOn, User $by): Role
    {
        Access::authorize('role.manage', __('Роли создаёт только владелец'), user: $by);
        $this->assertOwnFirm($basedOn);
        $title = trim($title);
        if (mb_strlen($title) < 2 || mb_strlen($title) > 60) {
            throw UserFacingException::because('title', __('Название роли — от 2 до 60 символов'));
        }
        $orgId = CurrentOrganization::instance()->requireId();
        if (Role::query()->where('organization_id', $orgId)->whereRaw('lower(title) = ?', [mb_strtolower($title)])->exists()) {
            throw UserFacingException::because('title', __('Роль с таким названием уже есть'));
        }

        return DB::transaction(function () use ($title, $basedOn, $orgId, $by) {
            $role = Role::query()->create([
                'name' => 'custom_'.Str::lower(Str::random(8)),
                'guard_name' => 'web',
                'organization_id' => $orgId,
                'title' => $title,
                'is_owner' => false,
                'description' => __('на основе роли «:role»', ['role' => $basedOn->title]),
            ]);
            $role->syncPermissions($basedOn->is_owner ? PermissionCatalog::codes() : $basedOn->permissions()->pluck('name')->all());
            if (! $basedOn->is_owner) {
                $rows = RoleLimit::query()->where('role_id', $basedOn->id)->get()->map(fn (RoleLimit $l) => [
                    'organization_id' => $orgId, 'role_id' => $role->id, 'code' => $l->code, 'value' => $l->value,
                    'created_at' => now(), 'updated_at' => now(),
                ])->all();
                DB::table('role_limits')->insert($rows);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            AuditLog::record('settings', 'role_created', $role, ['role' => $title, 'based_on' => $basedOn->title], $by);

            return $role;
        });
    }

    public function delete(Role $role, User $by): void
    {
        Access::authorize('role.manage', __('Роли удаляет только владелец'), user: $by);
        $this->assertOwnFirm($role);
        if ($role->is_owner) {
            throw UserFacingException::because('owner_role', __('Роль «Владелец» удалить нельзя'));
        }
        $count = $this->membersCount($role, true);
        if ($count > 0) {
            throw UserFacingException::because('role_in_use', trans_choice('На роли :count сотрудник. Сначала переведите его на другую роль|На роли :count сотрудника. Сначала переведите их на другую роль|На роли :count сотрудников. Сначала переведите их на другую роль', $count, ['count' => $count]));
        }
        $title = $role->title;
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AuditLog::record('settings', 'role_deleted', null, ['role' => $title], $by);
    }

    public function membersCount(Role $role, bool $includeBlocked = false): int
    {
        $userIds = DB::table('model_has_roles')->where('role_id', $role->id)->where('organization_id', $role->organization_id)->pluck('model_id');
        $q = Membership::query()->whereIn('user_id', $userIds);
        $q->whereIn('status', $includeBlocked ? ['active', 'invited', 'blocked'] : ['active']);

        return $q->count();
    }

    /** @return list<Role> roles of the current firm, owner first, then the templates in their order */
    public function roles(): array
    {
        $order = array_flip(RoleTemplates::codes());

        return Role::query()->where('organization_id', CurrentOrganization::instance()->requireId())->get()
            ->sortBy(fn (Role $r) => [$order[$r->name] ?? 100, $r->id])->values()->all();
    }

    private function assertOwnFirm(Role $role): void
    {
        if ((int) $role->organization_id !== CurrentOrganization::instance()->requireId()) {
            throw AccessDenied::because('access_denied', __('Роль не найдена в этой фирме'));
        }
    }

    private function roleOfUser(User $user): ?Role
    {
        $user->unsetRelation('roles');

        return $user->roles()->first();
    }

    /** @return array{permissions: list<string>, limits: array<string, ?int>} */
    private function summary(Role $role): array
    {
        return [
            'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
            'limits' => RoleLimit::query()->where('role_id', $role->id)->orderBy('code')->pluck('value', 'code')->all(),
        ];
    }
}
