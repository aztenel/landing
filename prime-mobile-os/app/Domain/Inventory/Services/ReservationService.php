<?php

namespace App\Domain\Inventory\Services;

use App\Support\Outbox\Outbox;
use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitReservation;
use App\Domain\Inventory\UnitTransitions;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Layer 2 of IMEI protection (architecture §2.7): adding a phone to a cart reserves it for the seller for
 * 30 minutes (setting sales.reservation_minutes). `reserved` is not a document and writes no goods journal
 * (TZ 02 §10.1); stock_units.status is updated under a row lock, the active reservation is unique per unit.
 */
class ReservationService
{
    public function __construct(private readonly AccessChecker $access) {}

    public function reserve(StockUnit|int $unit, User $user, ?string $cartKey = null): UnitReservation
    {
        if (! $this->access->can($user, 'sale.reserve')) {
            throw AccessDenied::because('access_denied', 'Нет права резервировать телефоны');
        }
        $minutes = CurrentOrganization::instance()->require()->settings()->reservationMinutes();

        return DB::transaction(function () use ($unit, $user, $cartKey, $minutes) {
            $locked = StockUnit::query()->whereKey($unit instanceof StockUnit ? $unit->id : $unit)->lockForUpdate()->firstOrFail();
            $active = UnitReservation::query()->where('stock_unit_id', $locked->id)->whereNull('released_at')->first();

            // somebody else's cart that already expired does not hold the phone (audit №51, TZ 07 scenario 16)
            if ($locked->status === UnitStatus::Reserved && $active && (int) $active->user_id !== $user->id && $active->expires_at->isPast()) {
                $active->forceFill(['released_at' => now(), 'release_reason' => 'expired'])->save();
                $locked->forceFill(['status' => UnitStatus::InStock->value])->save();
                Outbox::record('unit.changed', ['stock_unit_id' => $locked->id, 'action' => 'release', 'status' => UnitStatus::InStock->value], 'stock_unit', $locked->id);
                $active = null;
            }
            if ($locked->status === UnitStatus::Reserved && $active) {
                if ((int) $active->user_id !== $user->id) {
                    throw PostingException::because('unit_reserved', "Уже в корзине у {$active->user?->name} до ".$active->expires_at->setTimezone(CurrentOrganization::instance()->require()->timezone)->format('H:i'));
                }
                $active->forceFill(['expires_at' => now()->addMinutes($minutes), 'cart_key' => $cartKey ?? $active->cart_key])->save();

                return $active;
            }
            UnitTransitions::assert($locked->status, UnitStatus::Reserved, UnitAction::Reserve);

            $reservation = UnitReservation::query()->create([
                'stock_unit_id' => $locked->id, 'user_id' => $user->id, 'cart_key' => $cartKey,
                'reserved_at' => now(), 'expires_at' => now()->addMinutes($minutes),
            ]);
            $locked->forceFill(['status' => UnitStatus::Reserved->value])->save();
            Outbox::record('unit.changed', ['stock_unit_id' => $locked->id, 'action' => 'reserve', 'status' => UnitStatus::Reserved->value], 'stock_unit', $locked->id);

            return $reservation;
        });
    }

    /** Remove from the cart (or expire). Releasing someone else's reservation needs order.cancel_any. */
    public function release(UnitReservation $reservation, ?User $by = null, string $reason = 'removed'): void
    {
        if ($by && (int) $reservation->user_id !== $by->id && ! $this->access->can($by, 'order.cancel_any')) {
            throw AccessDenied::because('access_denied', 'Снять чужой резерв может старший продавец, админ или владелец');
        }
        DB::transaction(function () use ($reservation, $reason) {
            $unit = StockUnit::query()->whereKey($reservation->stock_unit_id)->lockForUpdate()->firstOrFail();
            $fresh = UnitReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($fresh->released_at !== null) {
                return;
            }
            if ($reason === 'expired' && $fresh->expires_at->isFuture()) {
                return; // renewed by its owner in the meantime (audit №51: the expiry job must not drop a live cart)
            }
            $fresh->forceFill(['released_at' => now(), 'release_reason' => $reason])->save();
            if ($unit->status === UnitStatus::Reserved) {
                UnitTransitions::assert(UnitStatus::Reserved, UnitStatus::InStock, UnitAction::ReleaseReservation);
                $unit->forceFill(['status' => UnitStatus::InStock->value])->save();
                Outbox::record('unit.changed', ['stock_unit_id' => $unit->id, 'action' => 'release', 'status' => UnitStatus::InStock->value], 'stock_unit', $unit->id);
            }
        });
    }

    /** Background job (every minute): release expired reservations of the current organization. */
    public function releaseExpired(): int
    {
        $count = 0;
        UnitReservation::query()->whereNull('released_at')->where('expires_at', '<', now())->get()
            ->each(function (UnitReservation $r) use (&$count) {
                $this->release($r, null, 'expired');
                $count++;
            });

        return $count;
    }
}
