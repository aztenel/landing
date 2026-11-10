<?php

namespace App\Domain\Tenancy\Access;

use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\RoleLimit;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Spatie\Permission\Models\Role;

/**
 * «Может ли пользователь X в фирме Y в магазине Z сделать действие A на сумму S» (TZ 03 §18 p.2).
 * Used by the cash register, admin screens, PWA and by document posting (PostingContext::authorize()).
 *
 *   $checker->decide($user, 'sale.discount', Limit::DiscountPerCheck, 600000)  // 6 000 ₸ in tiyn
 */
class AccessChecker
{
    public function decide(User $user, string $permission, ?Limit $limit = null, ?int $value = null, ?int $storeId = null): AccessDecision
    {
        $membership = $this->membership($user);
        if (! $membership || ! $membership->isActive()) {
            return AccessDecision::denied($permission, 'Доступ закрыт. Обратитесь к владельцу');
        }

        if (! PermissionCatalog::exists($permission)) {
            return AccessDecision::denied($permission, 'Нет права: '.$permission);
        }
        $role = $this->role($user);
        // The owner has every right of the catalog, including rights added later by new modules (audit №23), in every store.
        if ($role?->is_owner) {
            return AccessDecision::allowed($permission);
        }
        // a store the employee works in, with the mark «Продажи» / «Финансы» when the right needs it (audit №10)
        $scope = PermissionCatalog::storeScope($permission);
        if (! $membership->allowsStore($storeId, $scope)) {
            return AccessDecision::denied($permission, match ($scope) {
                'sales' => 'Нет доступа к продажам в этом магазине',
                'finance' => 'Нет доступа к деньгам этого магазина',
                default => 'Нет доступа к этому магазину',
            });
        }
        if (! $role || ! $role->hasPermissionTo($permission)) {
            return AccessDecision::denied($permission, 'Нет права: '.PermissionCatalog::title($permission));
        }

        // «З» — the role may do it only with an approval, every time.
        $approvalOnly = $this->roleLimit($role, $permission);
        if ($approvalOnly[0] && $approvalOnly[1] === 0) {
            return AccessDecision::approval($permission, null, 0, $value, 'Нужно разрешение владельца: '.PermissionCatalog::title($permission));
        }

        if ($limit !== null && $value !== null) {
            $limitValue = $this->limitValue($membership, $role, $limit);
            // a limit of 0 means «always an approval» (audit №48)
            if ($limitValue !== null && ($value > $limitValue || $limitValue === 0)) {
                return AccessDecision::approval($permission, $limit, $limitValue, $value, $this->limitMessage($limit, $limitValue, $value));
            }
        }

        return AccessDecision::allowed($permission);
    }

    public function can(User $user, string $permission, ?Limit $limit = null, ?int $value = null): bool
    {
        return $this->decide($user, $permission, $limit, $value)->isAllowed();
    }

    public function isOwner(User $user): bool
    {
        return (bool) $this->role($user)?->is_owner;
    }

    /** Effective limit (personal override → role value); null = unlimited. */
    public function limitValue(Membership $membership, Role $role, Limit $limit): ?int
    {
        [$found, $personal] = $membership->personalLimit($limit->value);
        if ($found) {
            return $personal;
        }

        return $this->roleLimit($role, $limit->value)[1];
    }

    public function membership(User $user): ?Membership
    {
        return Membership::query()->where('user_id', $user->id)->first();
    }

    public function role(User $user): ?Role
    {
        CurrentOrganization::instance()->require();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        /** @var Role|null */
        return $user->roles()->first();
    }

    /** @return array{0: bool, 1: int|null} [exists, value] */
    private function roleLimit(Role $role, string $code): array
    {
        $row = RoleLimit::query()->where('role_id', $role->id)->where('code', $code)->first();

        return $row ? [true, $row->value] : [false, null];
    }

    private function limitMessage(Limit $limit, int $limitValue, int $value): string
    {
        $fmt = match ($limit->unit()) {
            'money' => fn (int $v) => Money::kztMinor($v)->format(),
            'percent' => fn (int $v) => rtrim(rtrim(number_format($v / 100, 2, ',', ''), '0'), ',').' %',
            'days' => fn (int $v) => $v.' дн.',
            default => fn (int $v) => (string) $v,
        };

        if ($limit === Limit::DebtWithoutHistory) {
            return 'Долг клиенту без истории покупок — нужно разрешение владельца';
        }

        return sprintf('%s: %s больше вашего лимита %s', $limit->title(), $fmt($value), $fmt($limitValue));
    }
}
