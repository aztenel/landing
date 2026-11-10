<?php

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Shift;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Core of the shift of a store (audit №18, №57): open and close. Counting the cash, differences (documents РКС) and
 * the shift report (Ф10) are the Money module's part on top of these two calls.
 */
class Shifts
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly DocumentNumberAllocator $numbers,
    ) {}

    public function current(Store|int $store): ?Shift
    {
        return Shift::query()->where('store_id', $store instanceof Store ? $store->id : $store)->where('status', 'open')->first();
    }

    public function open(Store|int $store, User $user): Shift
    {
        $storeId = $store instanceof Store ? $store->id : $store;
        if (! $this->access->decide($user, 'shift.open_close', storeId: $storeId)->isAllowed()) {
            throw AccessDenied::because('access_denied', 'Открыть смену может сотрудник этого магазина');
        }
        try {
            return DB::transaction(function () use ($storeId, $user) {
                Store::query()->whereKey($storeId)->firstOrFail();
                $tz = CurrentOrganization::instance()->require()->timezone;
                $year = (int) now($tz)->format('Y');
                $shift = new Shift;
                $shift->forceFill([
                    'store_id' => $storeId, 'status' => 'open', 'opened_by' => $user->id, 'opened_at' => now(),
                    'number' => $this->numbers->allocate('СМН', $year), 'number_year' => $year,
                ])->save();

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            throw PostingException::because('shift_already_open', 'Смена этого магазина уже открыта');
        }
    }

    /** Close the shift (status «closed», or «auto_closed» by the night job: not reconciled). */
    public function close(Shift $shift, ?User $user, bool $auto = false): Shift
    {
        if (! $auto && ! $this->access->decide($user, 'shift.open_close', storeId: (int) $shift->store_id)->isAllowed()) {
            throw AccessDenied::because('access_denied', 'Закрыть смену может сотрудник этого магазина');
        }

        return DB::transaction(function () use ($shift, $user, $auto) {
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail(); // waits for postings in flight
            if (! $locked->isOpen()) {
                throw PostingException::because('shift_not_open', 'Смена уже закрыта');
            }
            $locked->forceFill(['status' => $auto ? 'auto_closed' : 'closed', 'closed_at' => now(), 'closed_by' => $user?->id])->save();

            return $locked;
        });
    }
}
