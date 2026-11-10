<?php

namespace App\Domain\Inventory\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Models\CostEntry;
use App\Domain\Inventory\Models\CostEntryLine;
use App\Domain\Inventory\Models\CostEntryPayment;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\PurchaseLine;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\MovementKind;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\InventoryLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\MoneyAccount;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use Illuminate\Support\Collection;

/**
 * «Внесение себестоимости» ВСБ (audit №4, docs/audit/decisions.md): the purchase price of phones that were received
 * without it (a seller's purchase, «лишние» of the opening balance, a revision surplus). Until then such a phone
 * (stock_units.cost_unknown) is not sold by anybody.
 *
 *  - for a purchase: every phone of that purchase still without a price at once; payment from accounts and/or supplier
 *    debt with a due date, like the purchase itself (right stock.cost_edit, credit — stock.receive_on_credit);
 *  - without a purchase (opening balance, surplus): only the owner (stock.cost_adjust), only the cost, no money/debt.
 * Writes: inventory qty 0 with +cost per phone (the flag is cleared), money −, supplier debt −. Does not affect profit.
 * Reversal: while none of the phones moved after the document (Reverser checks); the flag comes back.
 */
class CostEntryHandler extends AbstractDocumentHandler
{
    public function __construct(
        private readonly InventoryLedger $inventory,
        private readonly MoneyLedger $money,
        private readonly DebtLedger $debts,
    ) {}

    public function type(): DocumentType
    {
        return DocumentType::CostEntry;
    }

    public function requiresExchangeRate(Document $document): bool
    {
        $entry = CostEntry::query()->where('document_id', $document->id)->first();
        if ($entry?->currency === Currency::USD->value) {
            return true;
        }
        $accounts = CostEntryPayment::query()->where('document_id', $document->id)->pluck('account_id');

        return MoneyAccount::query()->whereIn('id', $accounts)->where('currency', Currency::USD->value)->exists();
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $ctx->requireStoreId();
        $entry = $this->entry($document);
        $lines = $this->lines($document);
        $payments = $this->payments($document);
        $currency = Currency::from($entry->currency);
        if ($lines->isEmpty()) {
            throw PostingException::because('empty', 'В документе нет телефонов');
        }
        if ($lines->pluck('stock_unit_id')->unique()->count() !== $lines->count()) {
            throw PostingException::because('duplicate_unit', 'Один телефон дважды в документе');
        }
        foreach ($lines as $line) {
            if ($line->price <= 0) {
                throw PostingException::because('bad_price', 'Цена закупа должна быть больше нуля');
            }
        }
        foreach ($payments as $p) {
            if ($p->amount <= 0) {
                throw PostingException::because('bad_payment', 'Сумма оплаты должна быть больше нуля');
            }
        }

        if (! $entry->purchase_document_id) {
            if ($entry->supplier_id || $payments->isNotEmpty() || $entry->due_date) {
                throw PostingException::because('no_purchase_no_money', 'Без прихода вносится только себестоимость: без поставщика, оплаты и долга');
            }
            if ($currency !== Currency::KZT) {
                throw PostingException::because('kzt_only', 'Без прихода себестоимость вносится в тенге');
            }

            return;
        }

        $purchaseDoc = Document::query()->whereKey($entry->purchase_document_id)->first();
        if (! $purchaseDoc || $purchaseDoc->type !== DocumentType::Purchase || $purchaseDoc->status !== DocumentStatus::Posted) {
            throw PostingException::because('purchase_not_found', 'Приход не найден или не проведён');
        }
        $purchase = Purchase::query()->where('document_id', $purchaseDoc->id)->firstOrFail();
        if ((int) $entry->supplier_id !== (int) $purchase->supplier_id || $entry->currency !== $purchase->currency) {
            throw PostingException::because('purchase_mismatch', 'Поставщик и валюта должны совпадать с приходом');
        }
        // all phones of the purchase that are still without a price — at once, no partial entry
        $waiting = StockUnit::query()
            ->whereIn('id', PurchaseLine::query()->where('document_id', $purchaseDoc->id)->whereNotNull('stock_unit_id')->pluck('stock_unit_id'))
            ->where('cost_unknown', true)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $given = $lines->pluck('stock_unit_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($waiting === [] || $given !== $waiting) {
            throw PostingException::because('all_lines_required', 'Внесите цену по всем телефонам прихода без себестоимости ('.count($waiting).' шт.)');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $plan = new LockPlan;
        foreach ($this->lines($document) as $line) {
            $plan->unit((int) $line->stock_unit_id);
        }
        foreach ($this->payments($document) as $p) {
            $plan->account((int) $p->account_id);
        }
        $entry = $this->entry($document);
        if ($entry->supplier_id) {
            $plan->counterparty(CounterpartyType::Supplier, (int) $entry->supplier_id, Currency::from($entry->currency));
        }

        return $plan;
    }

    public function reversalLockPlan(Document $document): LockPlan
    {
        $plan = new LockPlan;
        foreach ($this->lines($document) as $line) {
            $plan->unit((int) $line->stock_unit_id);
        }

        return $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $entry = $this->entry($document);
        $currency = Currency::from($entry->currency);
        $rate = $currency === Currency::USD ? $ctx->requireRate() : null;

        if ($entry->purchase_document_id) {
            $ctx->authorize('stock.cost_edit', message: 'Себестоимость прихода вносит админ или владелец');
        } else {
            $ctx->authorize('stock.cost_adjust', message: 'Себестоимость без прихода вносит владелец');
        }

        $total = 0;
        foreach ($this->lines($document) as $line) {
            $unit = $ctx->locks->unit((int) $line->stock_unit_id);
            if (! $unit->cost_unknown) {
                throw PostingException::because('cost_known', "Себестоимость телефона {$unit->public_code} уже известна — исправление делает владелец корректировкой (КСБ)");
            }
            $costKzt = Money::of((int) $line->price, $currency)->convert(Currency::KZT, $rate ?? Rate::one())->amount;
            $this->inventory->unitCost($ctx, $unit, $costKzt - (int) $unit->cost_kzt, MovementKind::CostEntry);
            $unit->forceFill([
                'cost_unknown' => false, 'cost_currency' => $currency->value,
                'cost_amount_orig' => (int) $line->price, 'cost_rate' => $rate?->toString(),
            ])->save();
            $line->forceFill(['cost_kzt' => $costKzt])->save();
            $total += (int) $line->price;
        }

        $covered = 0;
        foreach ($this->payments($document) as $payment) {
            $account = $this->money->account((int) $payment->account_id);
            $this->money->record($ctx, $account->id, -(int) $payment->amount, ArticleCode::SupplierPayment, [
                'counterparty_type' => CounterpartyType::Supplier->value, 'counterparty_id' => $entry->supplier_id,
            ]);
            $part = $account->currency === $currency
                ? (int) $payment->amount
                : Money::of((int) $payment->amount, $account->currency)->convert($currency, $ctx->requireRate())->amount;
            $payment->forceFill(['covered_amount' => $part])->save();
            $covered += $part;
        }
        if ($covered > $total) {
            throw PostingException::because('overpaid', 'Оплата больше суммы: '.Money::of($covered, $currency)->format().' > '.Money::of($total, $currency)->format());
        }

        $credit = $entry->purchase_document_id ? $total - $covered : 0;
        if ($credit > 0) {
            if (! $entry->due_date) {
                throw PostingException::because('due_date_required', 'Не оплачено '.Money::of($credit, $currency)->format().': укажите срок оплаты поставщику или оплату');
            }
            $ctx->authorize('stock.receive_on_credit', message: 'Долг поставщику оформляет админ или владелец');
            $this->debts->record($ctx, CounterpartyType::Supplier, (int) $entry->supplier_id, $currency, -$credit, DebtKind::PurchaseCredit, $entry->due_date, $rate);
        }

        $entry->forceFill(['total' => $total, 'credit_amount' => $credit])->save();
    }

    public function afterReverse(Document $original, PostingContext $ctx): void
    {
        foreach ($this->lines($original) as $line) {
            $ctx->locks->unit((int) $line->stock_unit_id)
                ->forceFill(['cost_unknown' => true, 'cost_currency' => null, 'cost_amount_orig' => null, 'cost_rate' => null])->save();
        }
    }

    private function entry(Document $document): CostEntry
    {
        return CostEntry::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных документа');
    }

    /** @return Collection<int, CostEntryLine> */
    private function lines(Document $document): Collection
    {
        return CostEntryLine::query()->where('document_id', $document->id)->orderBy('id')->get();
    }

    /** @return Collection<int, CostEntryPayment> */
    private function payments(Document $document): Collection
    {
        return CostEntryPayment::query()->where('document_id', $document->id)->orderBy('id')->get();
    }
}
