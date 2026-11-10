<?php

namespace App\Domain\Inventory\Services;

use App\Support\Outbox\Outbox;
use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\UnitTransitions;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Status-only transitions of TZ 02 §10.3 that are NOT documents and write no goods journal:
 * «Готов к продаже», «Отправить на подготовку», «Решение по проверке», «В ремонт». Logged in the activity log.
 * (Reservation: ReservationService; order hold: the Orders module via UnitAction::Order / CancelOrder.)
 */
class UnitStatusService
{
    public function __construct(private readonly AccessChecker $access) {}

    public function markReady(StockUnit $unit, User $user): StockUnit
    {
        return $this->change($unit, UnitStatus::InStock, UnitAction::MarkReady, $user, 'stock.prepare');
    }

    public function sendToPreparation(StockUnit $unit, User $user): StockUnit
    {
        return $this->change($unit, UnitStatus::Preparing, UnitAction::SendToPreparation, $user, 'stock.prepare_cost');
    }

    public function decideCheck(StockUnit $unit, UnitStatus $to, User $user): StockUnit
    {
        return $this->change($unit, $to, UnitAction::CheckDecision, $user, 'stock.prepare_cost');
    }

    public function toRepair(StockUnit $unit, User $user): StockUnit
    {
        return $this->change($unit, UnitStatus::Preparing, UnitAction::ToRepair, $user, 'stock.prepare_cost');
    }

    /**
     * Transitions of TZ 02 §10.3 that write no goods journal. Everything else (sale, return, transfer, write-off,
     * intake, …) changes the status only through InventoryLedger inside a posted document; the cart only through
     * ReservationService (it keeps unit_reservations in step).
     */
    public const WITHOUT_JOURNAL = [
        UnitAction::MarkReady, UnitAction::SendToPreparation, UnitAction::CheckDecision, UnitAction::ToRepair,
        UnitAction::Order, UnitAction::CancelOrder, UnitAction::MarkDefective,
    ];

    /** Generic entry point for modules (orders: in_stock ⇄ ordered). */
    public function change(StockUnit $unit, UnitStatus $to, UnitAction $action, User $user, string $permission, array $extra = []): StockUnit
    {
        if (! in_array($action, self::WITHOUT_JOURNAL, true)) {
            throw new LogicException("Unit action {$action->value} needs a document (InventoryLedger) or ReservationService, not a bare status change.");
        }
        if (! $this->access->can($user, $permission)) {
            throw AccessDenied::because('access_denied', 'Нет права на это действие');
        }

        return DB::transaction(function () use ($unit, $to, $action, $user, $extra) {
            $locked = StockUnit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            UnitTransitions::assert($from, $to, $action);
            $locked->forceFill(['status' => $to->value] + $extra)->save();
            Outbox::record('unit.changed', ['stock_unit_id' => $locked->id, 'action' => $action->value, 'status' => $to->value], 'stock_unit', $locked->id);
            activity('stock')->performedOn($locked)->causedBy($user)
                ->withProperties(['from' => $from?->value, 'to' => $to->value, 'action' => $action->value])
                ->log('status_changed');

            return $locked;
        });
    }
}
