<?php

namespace App\Domain\Warehouse\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Enums\IdentifierKind;
use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitIdentifier;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\MovementKind;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\InventoryLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\ShiftRequirement;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Tenancy\Access\Limit;
use App\Domain\Warehouse\Models\StockOp;
use App\Domain\Warehouse\Models\StockOpLine;
use App\Support\Imei\Imei;
use App\Support\Money\Currency;
use Illuminate\Support\Collection;

/**
 * Warehouse documents of TZ 02 §6.2 (one class, one instance per type — registered in LedgerServiceProvider):
 *   transfer_out №24  in_stock → in_transit (place «В пути» of this document); transfer.send
 *   transfer_in  №25–26 accepted → in_stock in store B, refused → back to store A; the out is «received»; transfer.accept
 *   write_off    №27  → written_off at cost, a reason; stock.write_off, L15 on the cost
 *   supplier_return №28–29 → returned_to_supplier; money to an account or our debt to the supplier gets smaller
 *   revision     №30–31 shortage → written_off, a phone written off before found → back with its cost, accessories ±;
 *                revision.post
 *   unit_edit    №48  model / IMEI / serial «было → стало» without movements; stock.unit_edit (sold: IMEI typo only)
 *   cost_adjustment №49 qty-0 row ± on a phone on hand; stock.cost_adjust (owner)
 * Statuses only by UnitTransitions (the ledger checks every move).
 */
class StockOpHandler extends AbstractDocumentHandler
{
    public function __construct(
        private readonly DocumentType $documentType,
        private readonly InventoryLedger $inventory,
        private readonly MoneyLedger $money,
        private readonly DebtLedger $debts,
    ) {}

    public static function for(DocumentType $type): \Closure
    {
        return fn ($app) => new self($type, $app->make(InventoryLedger::class), $app->make(MoneyLedger::class), $app->make(DebtLedger::class));
    }

    public function type(): DocumentType
    {
        return $this->documentType;
    }

    public function shiftRequirement(Document $document): ShiftRequirement
    {
        return $this->documentType === DocumentType::SupplierReturn ? ShiftRequirement::IfStoreCash : ShiftRequirement::Never;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $op = $this->op($document);
        $lines = $this->lines($document);
        if ($lines->isEmpty()) {
            throw PostingException::because('empty', 'В документе нет товара');
        }
        match ($this->documentType) {
            DocumentType::TransferOut => (! $op->to_store_id || (int) $op->to_store_id === (int) $document->store_id)
                ? throw PostingException::because('bad_target', 'Выберите другой магазин этой фирмы') : null,
            DocumentType::WriteOff, DocumentType::UnitEdit, DocumentType::CostAdjustment => trim((string) $op->reason) === ''
                ? throw PostingException::because('reason_required', 'Напишите причину') : null,
            DocumentType::SupplierReturn => (! $op->supplier_id || ! in_array($op->refund_mode, ['money', 'debt'], true) || $op->refund_amount < 0
                || $op->refund_mode === 'money' && ! $op->refund_account_id)
                ? throw PostingException::because('bad_refund', 'Укажите поставщика и как он возвращает деньги') : null,
            default => null,
        };
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $op = $this->op($document);
        $plan = new LockPlan;
        $store = $document->store_id ? Location::store((int) $document->store_id) : null;
        $outDoc = $this->documentType === DocumentType::TransferIn ? Document::query()->find($op->transfer_document_id) : null;
        foreach ($this->lines($document) as $l) {
            if ($l->stock_unit_id) {
                $plan->unit((int) $l->stock_unit_id);

                continue;
            }
            match ($this->documentType) {
                DocumentType::TransferOut => $plan->accessory((int) $l->accessory_id, $store)->accessory((int) $l->accessory_id, Location::transit($document->id)),
                DocumentType::TransferIn => $plan->accessory((int) $l->accessory_id, Location::transit($outDoc->id))
                    ->accessory((int) $l->accessory_id, $store)->accessory((int) $l->accessory_id, Location::store((int) $outDoc->store_id)),
                default => $plan->accessory((int) $l->accessory_id, $store),
            };
        }
        if ($op->refund_account_id) {
            $plan->account((int) $op->refund_account_id);
        }
        if ($this->documentType === DocumentType::SupplierReturn && $op->refund_mode === 'debt') {
            $plan->counterparty(CounterpartyType::Supplier, (int) $op->supplier_id, Currency::KZT);
        }

        return $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $op = $this->op($document);
        $lines = $this->lines($document);
        match ($this->documentType) {
            DocumentType::TransferOut => $this->transferOut($document, $ctx, $lines),
            DocumentType::TransferIn => $this->transferIn($document, $ctx, $op, $lines),
            DocumentType::WriteOff => $this->writeOff($document, $ctx, $lines),
            DocumentType::SupplierReturn => $this->supplierReturn($document, $ctx, $op, $lines),
            DocumentType::Revision => $this->revision($document, $ctx, $lines),
            DocumentType::UnitEdit => $this->unitEdit($ctx, $op, $lines),
            DocumentType::CostAdjustment => $this->costAdjustment($ctx, $lines),
            default => throw PostingException::because('bad_type', 'Неизвестный складской документ'),
        };
    }

    // ------------------------------------------------------------------ documents

    private function transferOut(Document $document, PostingContext $ctx, Collection $lines): void
    {
        $ctx->authorize('transfer.send', message: 'Отправить перемещение может сотрудник этого магазина');
        $store = Location::store((int) $document->store_id);
        $transit = Location::transit($document->id);
        foreach ($lines as $l) {
            if ($l->stock_unit_id) {
                $unit = $ctx->locks->unit((int) $l->stock_unit_id);
                $this->assertInStore($unit, (int) $document->store_id);
                $this->inventory->unitMove($ctx, $unit, $transit, MovementKind::Transfer, UnitAction::TransferSend, UnitStatus::InTransit);
            } else {
                $out = $this->inventory->accessoryOut($ctx, (int) $l->accessory_id, $store, (int) $l->qty, MovementKind::Transfer);
                $this->inventory->accessoryIn($ctx, (int) $l->accessory_id, $transit, (int) $l->qty, (int) $out->unit_cost_kzt, MovementKind::Transfer);
            }
        }
    }

    private function transferIn(Document $document, PostingContext $ctx, StockOp $op, Collection $lines): void
    {
        $ctx->authorize('transfer.accept', message: 'Принять перемещение может сотрудник магазина-получателя');
        $out = Document::query()->findOrFail($op->transfer_document_id);
        $outOp = $this->op($out);
        if ($outOp->status !== 'in_transit' || (int) $outOp->to_store_id !== (int) $document->store_id) {
            throw PostingException::because('transfer_not_pending', 'Это перемещение уже принято или идёт в другой магазин');
        }
        $transit = Location::transit($out->id);
        $here = Location::store((int) $document->store_id);
        $back = Location::store((int) $out->store_id);
        $sent = $this->lines($out);
        foreach ($sent->whereNotNull('stock_unit_id') as $s) {
            $l = $lines->firstWhere('stock_unit_id', $s->stock_unit_id);
            if (! $l) {
                throw PostingException::because('unit_missing', 'Отметьте каждый телефон: принят или отказ');
            }
            $unit = $ctx->locks->unit((int) $s->stock_unit_id);
            $this->inventory->unitMove($ctx, $unit, $l->refused_qty > 0 ? $back : $here, MovementKind::Transfer, UnitAction::TransferReceive, UnitStatus::InStock);
        }
        foreach ($sent->whereNotNull('accessory_id') as $s) {
            $l = $lines->firstWhere('accessory_id', $s->accessory_id);
            $accepted = (int) ($l->qty ?? 0);
            $refused = (int) ($l->refused_qty ?? 0);
            if ($accepted + $refused !== (int) $s->qty) {
                throw PostingException::because('qty_mismatch', 'Принято и отказано вместе должно быть столько, сколько отправили');
            }
            foreach ([[$accepted, $here], [$refused, $back]] as [$qty, $to]) {
                if ($qty > 0) {
                    $m = $this->inventory->accessoryOut($ctx, (int) $s->accessory_id, $transit, $qty, MovementKind::Transfer);
                    $this->inventory->accessoryIn($ctx, (int) $s->accessory_id, $to, $qty, (int) $m->unit_cost_kzt, MovementKind::Transfer);
                }
            }
        }
        $outOp->forceFill(['status' => 'received'])->save();
    }

    private function writeOff(Document $document, PostingContext $ctx, Collection $lines): void
    {
        $store = Location::store((int) $document->store_id);
        $cost = 0;
        foreach ($lines as $l) {
            if ($l->stock_unit_id) {
                $unit = $ctx->locks->unit((int) $l->stock_unit_id);
                $cost += (int) $unit->cost_kzt;
                $this->inventory->unitOut($ctx, $unit, MovementKind::WriteOff, UnitAction::WriteOff, UnitStatus::WrittenOff);
            } else {
                $m = $this->inventory->accessoryOut($ctx, (int) $l->accessory_id, $store, (int) $l->qty, MovementKind::WriteOff);
                $cost += -(int) $m->total_cost_kzt;
            }
        }
        $ctx->authorize('stock.write_off', Limit::WriteOffCost, $cost, 'write_off', 'Списание сверх лимита — нужна заявка владельцу');
    }

    private function supplierReturn(Document $document, PostingContext $ctx, StockOp $op, Collection $lines): void
    {
        $ctx->authorize('stock.return_supplier', message: 'Возврат поставщику оформляет админ или владелец');
        $store = Location::store((int) $document->store_id);
        foreach ($lines as $l) {
            if ($l->stock_unit_id) {
                $this->inventory->unitOut($ctx, $ctx->locks->unit((int) $l->stock_unit_id), MovementKind::SupplierReturn, UnitAction::SupplierReturn, UnitStatus::ReturnedToSupplier);
            } else {
                $this->inventory->accessoryOut($ctx, (int) $l->accessory_id, $store, (int) $l->qty, MovementKind::SupplierReturn);
            }
        }
        if ($op->refund_amount > 0) {
            $who = ['counterparty_type' => CounterpartyType::Supplier->value, 'counterparty_id' => (int) $op->supplier_id];
            $op->refund_mode === 'money'
                ? $this->money->record($ctx, (int) $op->refund_account_id, (int) $op->refund_amount, ArticleCode::SupplierReturnDiff, $who + ['comment' => 'Возврат поставщику'])
                : $this->debts->record($ctx, CounterpartyType::Supplier, (int) $op->supplier_id, Currency::KZT, (int) $op->refund_amount, DebtKind::Return, comment: 'Возврат поставщику');
        }
    }

    private function revision(Document $document, PostingContext $ctx, Collection $lines): void
    {
        $ctx->authorize('revision.post', message: 'Итог ревизии проводит админ или владелец');
        $store = Location::store((int) $document->store_id);
        foreach ($lines as $l) {
            if ($l->stock_unit_id) {
                $unit = $ctx->locks->unit((int) $l->stock_unit_id);
                $l->kind === 'shortage'
                    ? $this->inventory->unitOut($ctx, $unit, MovementKind::Shortage, UnitAction::WriteOff, UnitStatus::WrittenOff)
                    : $this->inventory->unitIn($ctx, $unit, $store, MovementKind::Surplus, UnitAction::SurplusKnown, UnitStatus::InStock);
            } elseif ((int) $l->qty < 0) {
                $this->inventory->accessoryOut($ctx, (int) $l->accessory_id, $store, -(int) $l->qty, MovementKind::Shortage);
            } elseif ((int) $l->qty > 0) {
                $avg = (int) $ctx->locks->accessoryCost((int) $l->accessory_id)->avg_cost_kzt;
                $this->inventory->accessoryIn($ctx, (int) $l->accessory_id, $store, (int) $l->qty, $avg, MovementKind::Surplus);
            }
        }
    }

    private function unitEdit(PostingContext $ctx, StockOp $op, Collection $lines): void
    {
        $unit = $ctx->locks->unit((int) $lines->first()->stock_unit_id);
        $changes = array_intersect_key((array) $op->changes, array_flip(['variant_id', 'imei1', 'imei2', 'serial']));
        if ($changes === []) {
            throw PostingException::because('nothing_to_change', 'Нечего исправлять');
        }
        if ($unit->status === UnitStatus::Sold && array_diff(array_keys($changes), ['imei1', 'imei2']) !== []) {
            throw PostingException::because('sold_imei_only', 'У проданного телефона можно исправить только опечатку в IMEI');
        }
        if (! in_array($unit->status, [UnitStatus::InStock, UnitStatus::Preparing, UnitStatus::OnCheck, UnitStatus::Defective, UnitStatus::Sold], true)) {
            throw PostingException::because('bad_status', 'Исправить карточку можно у телефона на складе или проданного');
        }
        $ctx->authorize('stock.unit_edit', approvalType: 'unit_edit', message: 'Исправление карточки — заявкой владельцу');
        $diff = [];
        foreach ($changes as $field => $new) {
            $new = $field === 'variant_id' ? (int) $new : ($new === null || $new === '' ? null : Imei::normalize((string) $new));
            if ($field !== 'variant_id' && $new !== null) {
                if (in_array($field, ['imei1', 'imei2'], true) && ! Imei::isValid($new)) {
                    throw PostingException::because('bad_imei', "IMEI {$new}: 15 цифр с верной контрольной цифрой");
                }
                if (UnitIdentifier::query()->where('value', $new)->where('stock_unit_id', '<>', $unit->id)->exists()) {
                    throw PostingException::because('duplicate_imei', "{$new} уже есть у другого телефона");
                }
            }
            $diff[$field] = [$unit->{$field}, $new];
            $this->applyField($unit, $field, $new);
        }
        $op->forceFill(['changes' => $diff])->save();
    }

    private function applyField(StockUnit $unit, string $field, mixed $value): void
    {
        $unit->forceFill([$field => $value])->save();
        if ($field === 'variant_id') {
            return;
        }
        $kind = ['imei1' => IdentifierKind::Imei1, 'imei2' => IdentifierKind::Imei2, 'serial' => IdentifierKind::Serial][$field];
        UnitIdentifier::query()->where('stock_unit_id', $unit->id)->where('kind', $kind->value)->delete();
        if ($value !== null) {
            UnitIdentifier::query()->create(['stock_unit_id' => $unit->id, 'kind' => $kind->value, 'value' => $value]);
        }
    }

    private function costAdjustment(PostingContext $ctx, Collection $lines): void
    {
        $ctx->authorize('stock.cost_adjust', message: 'Корректировку себестоимости делает только владелец');
        foreach ($lines as $l) {
            $this->inventory->unitCost($ctx, $ctx->locks->unit((int) $l->stock_unit_id), (int) $l->cost_kzt, MovementKind::CostAdjustment);
        }
    }

    // ------------------------------------------------------------------ reversal

    public function reversalLockPlan(Document $document): LockPlan
    {
        $plan = new LockPlan;
        if ($this->documentType === DocumentType::UnitEdit) {
            $plan->unit((int) $this->lines($document)->first()?->stock_unit_id);
        }

        return $plan;
    }

    public function assertReversible(Document $document, PostingContext $ctx): void
    {
        if ($this->documentType === DocumentType::TransferOut && $this->op($document)->status !== 'in_transit') {
            throw ReversalBlocked::because('transfer_received', 'Перемещение уже принято — сначала сторнируйте приём');
        }
    }

    public function afterReverse(Document $original, PostingContext $ctx): void
    {
        $op = $this->op($original);
        if ($this->documentType === DocumentType::TransferIn) {
            StockOp::query()->where('document_id', $op->transfer_document_id)->update(['status' => 'in_transit']);
        }
        if ($this->documentType === DocumentType::UnitEdit) {
            $unit = $ctx->locks->unit((int) $this->lines($original)->first()->stock_unit_id);
            foreach ((array) $op->changes as $field => [$old]) {
                $this->applyField($unit, $field, $old);
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    private function assertInStore(StockUnit $unit, int $storeId): void
    {
        if (! $unit->isInStore($storeId)) {
            throw PostingException::because('unit_other_store', "Телефон {$unit->public_code} не в этом магазине");
        }
    }

    private function op(Document $document): StockOp
    {
        return StockOp::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных складского документа');
    }

    /** @return Collection<int, StockOpLine> */
    private function lines(Document $document): Collection
    {
        return StockOpLine::query()->where('document_id', $document->id)->orderBy('id')->get();
    }
}
