<?php

namespace App\Domain\Ledger\Posting\Ledgers;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitIdentifier;
use App\Domain\Inventory\UnitTransitions;
use App\Domain\Ledger\Enums\MovementKind;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Models\InventoryMovement;
use App\Domain\Ledger\Posting\PostingContext;
use App\Support\Money\Rounding;
use LogicException;

/**
 * The ONLY writer of inventory_movements and of the goods copies (stock_units.status/location/cost/last_movement_id,
 * accessory_balances, accessory_costs). Every method checks invariants: allowed status transition (UnitTransitions),
 * balance ≥ 0, average cost rules of TZ 02 §4.2. Rows must be locked via the handler's LockPlan.
 */
class InventoryLedger
{
    // ------------------------------------------------------------------ serialized units

    /**
     * +1 unit into a place (purchase, opening, customer return, trade-in, transfer receive, surplus).
     *
     * @param  int|null  $unitCostKzt  new cost of the unit (purchase/opening/trade-in); null keeps the current cost (return)
     * @param  array{currency?: string, amount?: int, rate?: string}  $costOrig  original purchase currency data
     */
    public function unitIn(
        PostingContext $ctx,
        StockUnit $unit,
        Location $to,
        MovementKind $kind,
        UnitAction $action,
        UnitStatus $statusAfter,
        ?int $unitCostKzt = null,
        array $costOrig = [],
    ): InventoryMovement {
        $unit = $ctx->locks->unit($unit->id);
        UnitTransitions::assert($unit->status, $statusAfter, $action);
        if ($unitCostKzt !== null && $unitCostKzt < 0) {
            throw new LogicException('Unit cost cannot be negative.');
        }
        $cost = $unitCostKzt ?? (int) $unit->cost_kzt;

        $movement = $this->insert($ctx, [
            'kind' => $kind->value,
            'stock_unit_id' => $unit->id,
            'location_type' => $to->type->value,
            'location_id' => $to->id,
            'qty' => 1,
            'unit_cost_kzt' => $cost,
            'total_cost_kzt' => $cost,
            'cost_currency' => $costOrig['currency'] ?? null,
            'cost_amount_orig' => $costOrig['amount'] ?? null,
            'exchange_rate' => $costOrig['rate'] ?? null,
            'status_before' => $unit->status?->value,
            'status_after' => $statusAfter->value,
            'location_before_type' => $unit->location_type,
            'location_before_id' => $unit->location_id,
            // for a unit: its cost before / after this movement when the movement sets the cost (purchase, opening,
            // trade-in of a known phone). The reversal restores exactly the previous cost (TZ 02 §10.3).
            'avg_cost_before' => $unitCostKzt !== null ? (int) $unit->cost_kzt : null,
            'avg_cost_after' => $unitCostKzt !== null ? $cost : null,
        ]);

        $changes = [
            'status' => $statusAfter->value,
            'location_type' => $to->type->value,
            'location_id' => $to->id,
            'last_movement_id' => $movement->id,
        ];
        if ($unitCostKzt !== null) {
            $changes += [
                'cost_kzt' => $cost,
                'cost_currency' => $costOrig['currency'] ?? 'KZT',
                'cost_amount_orig' => $costOrig['amount'] ?? $cost,
                'cost_rate' => $costOrig['rate'] ?? null,
            ];
        }
        $unit->forceFill($changes)->save();

        return $movement;
    }

    /** −1 unit from its current place (sale, write-off, supplier return, shortage). The unit leaves stock. */
    public function unitOut(PostingContext $ctx, StockUnit $unit, MovementKind $kind, UnitAction $action, UnitStatus $statusAfter): InventoryMovement
    {
        $unit = $ctx->locks->unit($unit->id);
        $from = $unit->location();
        if ($from === null) {
            throw InsufficientStock::because('unit_not_on_hand', "Телефона {$unit->public_code} нет на складе");
        }
        UnitTransitions::assert($unit->status, $statusAfter, $action);

        $cost = (int) $unit->cost_kzt;
        $movement = $this->insert($ctx, [
            'kind' => $kind->value,
            'stock_unit_id' => $unit->id,
            'location_type' => $from->type->value,
            'location_id' => $from->id,
            'qty' => -1,
            'unit_cost_kzt' => $cost,
            'total_cost_kzt' => -$cost,
            'status_before' => $unit->status?->value,
            'status_after' => $statusAfter->value,
            'location_before_type' => $from->type->value,
            'location_before_id' => $from->id,
        ]);

        $unit->forceFill([
            'status' => $statusAfter->value,
            'location_type' => null,
            'location_id' => null,
            'last_movement_id' => $movement->id,
        ])->save();

        return $movement;
    }

    /**
     * Move a unit between places: −1 at the current place, +1 at $to (transfer send / receive, revision «не в том
     * магазине»). Both rows carry the same status_before/after.
     *
     * @return array{0: InventoryMovement, 1: InventoryMovement}
     */
    public function unitMove(PostingContext $ctx, StockUnit $unit, Location $to, MovementKind $kind, UnitAction $action, UnitStatus $statusAfter): array
    {
        $unit = $ctx->locks->unit($unit->id);
        $from = $unit->location() ?? throw InsufficientStock::because('unit_not_on_hand', "Телефона {$unit->public_code} нет на складе");
        UnitTransitions::assert($unit->status, $statusAfter, $action);
        $cost = (int) $unit->cost_kzt;
        $common = [
            'kind' => $kind->value, 'stock_unit_id' => $unit->id, 'unit_cost_kzt' => $cost,
            'status_before' => $unit->status?->value, 'status_after' => $statusAfter->value,
            'location_before_type' => $from->type->value, 'location_before_id' => $from->id,
        ];
        $out = $this->insert($ctx, $common + [
            'location_type' => $from->type->value, 'location_id' => $from->id, 'qty' => -1, 'total_cost_kzt' => -$cost,
        ]);
        $in = $this->insert($ctx, $common + [
            'location_type' => $to->type->value, 'location_id' => $to->id, 'qty' => 1, 'total_cost_kzt' => $cost,
        ]);
        $unit->forceFill([
            'status' => $statusAfter->value, 'location_type' => $to->type->value, 'location_id' => $to->id, 'last_movement_id' => $in->id,
        ])->save();

        return [$out, $in];
    }

    /** qty 0 row changing the unit cost by $deltaKzt (preparation costs, cost adjustment). */
    public function unitCost(PostingContext $ctx, StockUnit $unit, int $deltaKzt, MovementKind $kind): InventoryMovement
    {
        $unit = $ctx->locks->unit($unit->id);
        $place = $unit->location() ?? throw PostingException::because('unit_not_on_hand', 'Затраты нельзя добавить к проданному телефону — это расход');
        $newCost = (int) $unit->cost_kzt + $deltaKzt;
        if ($newCost < 0) {
            throw PostingException::because('negative_cost', 'Себестоимость не может стать отрицательной');
        }
        $movement = $this->insert($ctx, [
            'kind' => $kind->value, 'stock_unit_id' => $unit->id,
            'location_type' => $place->type->value, 'location_id' => $place->id,
            'qty' => 0, 'unit_cost_kzt' => $deltaKzt, 'total_cost_kzt' => $deltaKzt,
            'status_before' => $unit->status?->value, 'status_after' => $unit->status?->value,
            'location_before_type' => $place->type->value, 'location_before_id' => $place->id,
            'avg_cost_before' => (int) $unit->cost_kzt, 'avg_cost_after' => $newCost,
        ]);
        $unit->forceFill(['cost_kzt' => $newCost, 'last_movement_id' => $movement->id])->save();

        return $movement;
    }

    // ------------------------------------------------------------------ accessories

    /**
     * +qty accessories into a place at a unit cost; recomputes the moving average (TZ 02 §4.2):
     * new avg = (qty_total × avg + qty × price) / (qty_total + qty), half-up to 1 tiyn.
     */
    public function accessoryIn(PostingContext $ctx, int $accessoryId, Location $place, int $qty, int $unitCostKzt, MovementKind $kind, array $costOrig = []): InventoryMovement
    {
        if ($qty <= 0 || $unitCostKzt < 0) {
            throw new LogicException('accessoryIn needs qty > 0 and a non-negative cost.');
        }
        $cost = $ctx->locks->accessoryCost($accessoryId);
        $balance = $ctx->locks->accessoryBalance($place, $accessoryId);

        $avgBefore = (int) $cost->avg_cost_kzt;
        $totalBefore = (int) $cost->total_qty;
        $avgAfter = Rounding::HalfUp->divide($totalBefore * $avgBefore + $qty * $unitCostKzt, $totalBefore + $qty);

        $movement = $this->insert($ctx, [
            'kind' => $kind->value, 'accessory_id' => $accessoryId,
            'location_type' => $place->type->value, 'location_id' => $place->id,
            'qty' => $qty, 'unit_cost_kzt' => $unitCostKzt, 'total_cost_kzt' => $qty * $unitCostKzt,
            'cost_currency' => $costOrig['currency'] ?? null, 'cost_amount_orig' => $costOrig['amount'] ?? null,
            'exchange_rate' => $costOrig['rate'] ?? null,
            'avg_cost_before' => $avgBefore, 'avg_cost_after' => $avgAfter,
        ]);

        $cost->forceFill(['total_qty' => $totalBefore + $qty, 'avg_cost_kzt' => $avgAfter, 'last_movement_id' => $movement->id, 'updated_at' => $ctx->now])->save();
        $balance->forceFill(['qty' => (int) $balance->qty + $qty, 'last_movement_id' => $movement->id, 'updated_at' => $ctx->now])->save();

        return $movement;
    }

    /** −qty accessories from a place at the current average cost (the average does not change). No negative stock. */
    public function accessoryOut(PostingContext $ctx, int $accessoryId, Location $place, int $qty, MovementKind $kind): InventoryMovement
    {
        if ($qty <= 0) {
            throw new LogicException('accessoryOut needs qty > 0.');
        }
        $cost = $ctx->locks->accessoryCost($accessoryId);
        $balance = $ctx->locks->accessoryBalance($place, $accessoryId);
        if ((int) $balance->qty < $qty) {
            $name = Accessory::query()->whereKey($accessoryId)->value('name_ru') ?? "#{$accessoryId}";
            throw InsufficientStock::because('accessory_short', "Нет на остатке: {$name} (есть {$balance->qty} шт., нужно {$qty}). Позовите админа: сделать приход или ревизию", [
                'accessory_id' => $accessoryId, 'available' => (int) $balance->qty, 'requested' => $qty,
            ]);
        }
        $avg = (int) $cost->avg_cost_kzt;

        $movement = $this->insert($ctx, [
            'kind' => $kind->value, 'accessory_id' => $accessoryId,
            'location_type' => $place->type->value, 'location_id' => $place->id,
            'qty' => -$qty, 'unit_cost_kzt' => $avg, 'total_cost_kzt' => -$qty * $avg,
            'avg_cost_before' => $avg, 'avg_cost_after' => $avg,
        ]);

        $cost->forceFill(['total_qty' => (int) $cost->total_qty - $qty, 'last_movement_id' => $movement->id, 'updated_at' => $ctx->now])->save();
        $balance->forceFill(['qty' => (int) $balance->qty - $qty, 'last_movement_id' => $movement->id, 'updated_at' => $ctx->now])->save();

        return $movement;
    }

    // ------------------------------------------------------------------ reversal

    /**
     * Inverse row for a movement of the original document. Units go back to exactly status_before / location_before
     * (TZ 02 §10.3); a unit whose creation is reversed gets status NULL («нет в учёте») and its IMEIs are released.
     * Accessories: reversing an inflow uses the TZ 02 §4.2 formula and is blocked if already sold.
     */
    public function reverse(PostingContext $ctx, InventoryMovement $original): InventoryMovement
    {
        return $original->stock_unit_id
            ? $this->reverseUnitMovement($ctx, $original)
            : $this->reverseAccessoryMovement($ctx, $original);
    }

    private function reverseUnitMovement(PostingContext $ctx, InventoryMovement $original): InventoryMovement
    {
        $unit = $ctx->locks->unit((int) $original->stock_unit_id);
        $restoreStatus = $original->status_before;
        // a cart reservation / order hold was consumed by the reversed document: the unit comes back free
        if ($restoreStatus === UnitStatus::Reserved || $restoreStatus === UnitStatus::Ordered) {
            $restoreStatus = UnitStatus::InStock;
        }
        UnitTransitions::assertReversal($unit->status, $restoreStatus);
        $qty = (int) $original->qty;

        $movement = $this->insert($ctx, [
            'kind' => MovementKind::Reversal->value,
            'stock_unit_id' => $unit->id,
            'location_type' => $original->location_type,
            'location_id' => $original->location_id,
            'qty' => -$qty,
            'unit_cost_kzt' => $original->unit_cost_kzt,
            'total_cost_kzt' => -$original->total_cost_kzt,
            'status_before' => $unit->status?->value,
            'status_after' => $restoreStatus?->value,
            'location_before_type' => $unit->location_type,
            'location_before_id' => $unit->location_id,
            'reversal_of_entry_id' => $original->id,
        ]);

        $changes = ['last_movement_id' => $movement->id];
        if ($qty === 0) {
            $changes['cost_kzt'] = (int) $unit->cost_kzt - (int) $original->total_cost_kzt;
        } else {
            $changes['status'] = $restoreStatus?->value;
            $changes['location_type'] = $original->location_before_type;
            $changes['location_id'] = $original->location_before_id;
            if ($qty > 0 && $original->avg_cost_before !== null) {
                // the reversed intake had set the cost (purchase, opening, trade-in): restore the previous cost
                $changes += $this->previousCost($unit, $original);
            }
        }
        $unit->forceFill($changes)->save();

        if ($qty !== 0 && $restoreStatus === null) {
            // creation reversed: the phone is not in stock any more, its IMEIs may be entered again correctly
            UnitIdentifier::query()->where('stock_unit_id', $unit->id)->delete();
        }

        return $movement;
    }

    private function reverseAccessoryMovement(PostingContext $ctx, InventoryMovement $original): InventoryMovement
    {
        $accessoryId = (int) $original->accessory_id;
        $place = $original->location();
        $qty = (int) $original->qty;

        if ($qty < 0) {
            // an outflow is undone by an inflow at the same unit cost (recomputes the average like a return)
            $cost = $ctx->locks->accessoryCost($accessoryId);
            $balance = $ctx->locks->accessoryBalance($place, $accessoryId);
            $n = -$qty;
            $avgBefore = (int) $cost->avg_cost_kzt;
            $totalBefore = (int) $cost->total_qty;
            $avgAfter = Rounding::HalfUp->divide($totalBefore * $avgBefore + $n * (int) $original->unit_cost_kzt, $totalBefore + $n);
            $movement = $this->insert($ctx, [
                'kind' => MovementKind::Reversal->value, 'accessory_id' => $accessoryId,
                'location_type' => $place->type->value, 'location_id' => $place->id,
                'qty' => $n, 'unit_cost_kzt' => $original->unit_cost_kzt, 'total_cost_kzt' => -$original->total_cost_kzt,
                'avg_cost_before' => $avgBefore, 'avg_cost_after' => $avgAfter,
                'reversal_of_entry_id' => $original->id,
            ]);
            $cost->forceFill(['total_qty' => $totalBefore + $n, 'avg_cost_kzt' => $avgAfter, 'last_movement_id' => $movement->id, 'updated_at' => $ctx->now])->save();
            $balance->forceFill(['qty' => (int) $balance->qty + $n, 'last_movement_id' => $movement->id, 'updated_at' => $ctx->now])->save();

            return $movement;
        }

        // reversal of an inflow: new avg = (qty × avg − n × price) / (qty − n)
        $cost = $ctx->locks->accessoryCost($accessoryId);
        $balance = $ctx->locks->accessoryBalance($place, $accessoryId);
        $n = $qty;
        $price = (int) $original->unit_cost_kzt;
        $totalBefore = (int) $cost->total_qty;
        $avgBefore = (int) $cost->avg_cost_kzt;
        if ((int) $balance->qty < $n || $totalBefore < $n) {
            throw ReversalBlocked::because('accessory_sold', 'Часть товара уже продана — оформите возврат поставщику', [
                'accessory_id' => $accessoryId, 'available' => (int) $balance->qty, 'needed' => $n,
            ]);
        }
        $totalAfter = $totalBefore - $n;
        $avgAfter = $totalAfter === 0 ? 0 : Rounding::HalfUp->divide($totalBefore * $avgBefore - $n * $price, $totalAfter);
        if ($totalAfter > 0 && $avgAfter <= 0) {
            throw ReversalBlocked::because('accessory_avg', 'Сторно невозможно: средняя себестоимость станет ≤ 0 — оформите возврат поставщику');
        }
        $movement = $this->insert($ctx, [
            'kind' => MovementKind::Reversal->value, 'accessory_id' => $accessoryId,
            'location_type' => $place->type->value, 'location_id' => $place->id,
            'qty' => -$n, 'unit_cost_kzt' => $price, 'total_cost_kzt' => -$n * $price,
            'avg_cost_before' => $avgBefore, 'avg_cost_after' => $avgAfter,
            'reversal_of_entry_id' => $original->id,
        ]);
        $cost->forceFill(['total_qty' => $totalAfter, 'avg_cost_kzt' => $avgAfter, 'last_movement_id' => $movement->id, 'updated_at' => $ctx->now])->save();
        $balance->forceFill(['qty' => (int) $balance->qty - $n, 'last_movement_id' => $movement->id, 'updated_at' => $ctx->now])->save();

        return $movement;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Cost columns of a unit as they were before the cost-setting intake $original: the cost from the journal
     * (avg_cost_before) and the purchase currency data of the previous live cost-setting movement (none: «—»).
     *
     * @return array{cost_kzt: int, cost_currency: ?string, cost_amount_orig: ?int, cost_rate: ?string}
     */
    private function previousCost(StockUnit $unit, InventoryMovement $original): array
    {
        $previous = InventoryMovement::query()
            ->where('stock_unit_id', $unit->id)
            ->where('id', '<', $original->id)
            ->where('qty', 1)
            ->whereNotNull('avg_cost_after')
            ->whereNull('reversal_of_entry_id')
            ->whereNotExists(fn ($q) => $q->from('inventory_movements as r')->whereColumn('r.reversal_of_entry_id', 'inventory_movements.id'))
            ->orderByDesc('id')
            ->first();

        return [
            'cost_kzt' => (int) $original->avg_cost_before,
            'cost_currency' => $previous ? ($previous->cost_currency ?? 'KZT') : null,
            'cost_amount_orig' => $previous ? ($previous->cost_amount_orig ?? (int) $previous->avg_cost_after) : null,
            'cost_rate' => $previous?->exchange_rate,
        ];
    }

    private function insert(PostingContext $ctx, array $attributes): InventoryMovement
    {
        $movement = new InventoryMovement;
        $movement->forceFill($ctx->entryDefaults() + $attributes)->save();

        return $movement;
    }
}
