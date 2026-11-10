<?php

namespace App\Support\Ui;

use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The store a person works in right now (cash register, shift, stock lists): a seller — their store; someone with
 * several stores chooses, the choice is kept in the session. Never trust a store id from the browser without allows().
 */
final class WorkingStore
{
    private const SESSION = 'pm.working_store';

    /** @return Collection<int, Store> stores the user may work in */
    public static function available(User $user): Collection
    {
        $access = app(AccessChecker::class);
        $stores = Store::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
        if ($access->isOwner($user)) {
            return $stores;
        }
        $membership = $access->membership($user);

        return $stores->filter(fn (Store $s) => $membership?->allowsStore($s->id))->values();
    }

    public static function get(User $user): ?Store
    {
        $stores = self::available($user);
        $chosen = session(self::SESSION);

        return $stores->firstWhere('id', $chosen) ?? $stores->first();
    }

    public static function set(User $user, int $storeId): Store
    {
        $store = self::available($user)->firstWhere('id', $storeId)
            ?? throw \App\Domain\Tenancy\Access\Exceptions\AccessDenied::because('access_denied', 'Нет доступа к этому магазину');
        session([self::SESSION => $store->id]);

        return $store;
    }
}
