<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Shell\Services\AuditLog;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Membership;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Допуск интегратора (TZ 03 §13): without it the integrator sees no row of the firm. The owner allows it with his
 * password for 1 / 4 / 24 hours — a membership with the role «Техподдержка» (look only: no posting, prices, roles,
 * devices, full IIN) that ends by itself; «Закрыть доступ» ends it at once. Everything is in the activity log.
 */
final class IntegratorAccess
{
    public const ROLE = 'support';

    public const VIEW = ['report.dashboard', 'report.sales', 'report.profit', 'report.stock_value', 'report.aging', 'report.used',
        'stock.passport', 'stock.view_cost', 'stock.view_supplier', 'stock.other_stores', 'customer.view', 'debt.customer_report', 'debt.supplier_view',
        'money.balance', 'audit.view'];

    public function grant(User $integrator, int $hours, string $purpose, User $owner, string $ownerPassword): Membership
    {
        if (! app(\App\Domain\Tenancy\Access\AccessChecker::class)->isOwner($owner)) {
            throw UserFacingException::because('owner_only', __('Допуск даёт только владелец'));
        }
        if (! Hash::check($ownerPassword, $owner->getAuthPassword())) {
            throw UserFacingException::because('password', __('Пароль не подходит'));
        }
        if (! $integrator->is_superadmin) {
            throw UserFacingException::because('not_integrator', __('Это не учётная запись интегратора'));
        }
        if (! in_array($hours, [1, 4, 24], true) || mb_strlen(trim($purpose)) < 5) {
            throw UserFacingException::because('support_form', __('Укажите цель и срок: 1, 4 или 24 часа'));
        }
        $org = CurrentOrganization::instance()->require();
        $role = Role::query()->firstOrCreate(['name' => self::ROLE, 'guard_name' => 'web', 'organization_id' => $org->id], ['title' => 'Техподдержка', 'is_owner' => false]);
        $role->syncPermissions(self::VIEW);
        $m = Membership::query()->updateOrCreate(['user_id' => $integrator->id], ['status' => MembershipStatus::Active, 'limits' => new \stdClass, 'position' => __('Техподдержка')]);
        $m->forceFill(['support_until' => now()->addHours($hours), 'support_purpose' => trim($purpose)])->save();
        foreach (\App\Domain\Tenancy\Models\Store::query()->pluck('id') as $storeId) {
            $m->stores()->updateOrCreate(['store_id' => $storeId], ['can_sales' => false, 'can_finance' => true]);
        }
        $integrator->unsetRelation('roles');
        $integrator->syncRoles([$role]);
        AuditLog::record('support', 'support.granted', $m, ['hours' => $hours, 'purpose' => trim($purpose)], $owner);

        return $m;
    }

    public function close(Membership $m, User $owner): void
    {
        $m->forceFill(['support_until' => now()->subSecond()])->save();
        AuditLog::record('support', 'support.closed', $m, [], $owner);
    }

    /** Active support in the firm — for the strip «Идёт техподдержка до 16:30» shown to everybody. */
    public static function active(): ?Membership
    {
        return CurrentOrganization::instance()->has()
            ? Membership::query()->whereNotNull('support_until')->where('support_until', '>', now())->where('status', MembershipStatus::Active->value)->first()
            : null;
    }
}
