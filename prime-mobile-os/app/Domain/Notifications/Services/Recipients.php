<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Membership;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who of the CURRENT organization receives something: owners, people with a permission. Only active members
 * (a dismissed or blocked employee, an integrator never get notifications — TZ 03 §15). Uses AccessChecker,
 * not $user->can(): spatie does not look at the membership status (CORE.md §13 p.6).
 */
class Recipients
{
    public function __construct(private readonly AccessChecker $access) {}

    /** @return Collection<int, User> active owners of the current organization */
    public function owners(): Collection
    {
        $orgId = CurrentOrganization::instance()->requireId();
        $ids = DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.organization_id', $orgId)
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('r.is_owner', true)
            ->pluck('mr.model_id');

        return $this->activeUsers($ids);
    }

    /** @return Collection<int, User> active members whose role has the permission (and the store, if given) */
    public function withPermission(string $permission, ?int $storeId = null): Collection
    {
        $ids = Membership::query()->where('status', MembershipStatus::Active->value)->pluck('user_id');

        return User::query()->whereIn('id', $ids)->orderBy('id')->get()
            ->filter(fn (User $u) => ! $this->access->decide($u, $permission, storeId: $storeId)->isDenied())
            ->values();
    }

    public function isActiveMember(User|int $user): bool
    {
        return Membership::query()->where('user_id', $user instanceof User ? $user->id : $user)
            ->where('status', MembershipStatus::Active->value)->exists();
    }

    /** «Дана» from «Дана Сапарова» — for short texts (lock screen, Telegram). */
    public static function firstName(?User $user): string
    {
        if (! $user) {
            return __('Сотрудник');
        }
        $parts = preg_split('/\s+/u', trim((string) $user->name));

        return $parts[0] ?? (string) $user->name;
    }

    /** @param  iterable<int>  $ids */
    private function activeUsers(iterable $ids): Collection
    {
        $active = Membership::query()->where('status', MembershipStatus::Active->value)
            ->whereIn('user_id', collect($ids)->all())->pluck('user_id');

        return User::query()->whereIn('id', $active)->orderBy('id')->get();
    }
}
