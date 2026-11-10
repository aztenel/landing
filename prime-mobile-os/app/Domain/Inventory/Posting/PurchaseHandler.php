<?php

namespace App\Domain\Inventory\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitCondition;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\PurchaseLine;
use App\Domain\Inventory\Models\PurchasePayment;
use App\Domain\Inventory\Services\UnitIntake;
use App\Domain\Ledger\Enums\DebtKind;
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
 * Приход (TZ 02 §6.2 №1–4): phones by IMEI (+1 each, piece cost = price × document rate) and accessories
 * (+N, moving average); paid immediately from accounts (₸ or $) and/or left as supplier debt in the purchase
 * currency with a due date. New phones → in_stock, used → preparing.
 */
class PurchaseHandler extends AbstractDocumentHandler
{
    public function __construct(
        private readonly UnitIntake $intake,
        private readonly InventoryLedger $inventory,
        private readonly MoneyLedger $money,
        private readonly DebtLedger $debts,
    ) {}

    public function type(): DocumentType
    {
        return DocumentType::Purchase;
    }

    public function requiresExchangeRate(Document $document): bool
    {
        $purchase = Purchase::query()->where('document_id', $document->id)->first();
        if ($purchase?->currency === Currency::USD->value) {
            return true;
        }
        $accounts = PurchasePayment::query()->where('document_id', $document->id)->pluck('account_id');

        return MoneyAccount::query()->whereIn('id', $accounts)->where('currency', Currency::USD->value)->exists();
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $ctx->requireStoreId();
        $purchase = $this->purchase($document);
        if (! $purchase->supplier_id) {
            throw PostingException::because('supplier_required', 'Укажите поставщика');
        }
        Currency::from($purchase->currency);
        $lines = $this->lines($document);
        if ($lines->isEmpty()) {
            throw PostingException::because('empty', 'В приходе нет товара');
        }
        foreach ($lines as $line) {
            if ($line->line_type === 'accessory') {
                if (! $line->accessory_id || $line->qty <= 0) {
                    throw PostingException::because('bad_line', 'Строка аксессуара: укажите товар и количество');
                }
                // price null = a seller's intake (ТЗ 07 сценарий 7): received, but not sold until the admin re-posts it with prices
            } elseif ($line->line_type !== 'unit') {
                throw PostingException::because('bad_line', 'Неизвестный тип строки');
            }
            if ($line->price !== null && $line->price < 0) {
                throw PostingException::because('bad_price', 'Цена закупа не может быть отрицательной');
            }
        }
        $this->intake->validate($lines->where('line_type', 'unit'));

        $payments = PurchasePayment::query()->where('document_id', $document->id)->get();
        $unknown = $lines->contains(fn ($l) => $l->price === null);
        if ($unknown && ($payments->isNotEmpty() || $purchase->due_date)) {
            throw PostingException::because('cost_unknown_payment', 'Без цен закупа нельзя оплачивать приход или записывать долг');
        }
        foreach ($payments as $p) {
            if ($p->amount <= 0) {
                throw PostingException::because('bad_payment', 'Сумма оплаты должна быть больше нуля');
            }
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $plan = new LockPlan;
        $store = Location::store($ctx->requireStoreId());
        foreach ($this->lines($document)->where('line_type', 'accessory') as $line) {
            $plan->accessory((int) $line->accessory_id, $store);
        }
        foreach (PurchasePayment::query()->where('document_id', $document->id)->get() as $p) {
            $plan->account((int) $p->account_id);
        }
        $purchase = $this->purchase($document);
        $plan->counterparty(CounterpartyType::Supplier, (int) $purchase->supplier_id, Currency::from($purchase->currency));

        return $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $purchase = $this->purchase($document);
        $currency = Currency::from($purchase->currency);
        $lines = $this->lines($document);
        $store = Location::store($ctx->requireStoreId());
        $rate = $currency === Currency::USD ? $ctx->requireRate() : null;

        if ($lines->contains('line_type', 'unit')) {
            $ctx->authorize('stock.receive');
        }
        if ($lines->contains('line_type', 'accessory')) {
            $ctx->authorize('stock.receive_acc');
        }
        if ($lines->contains(fn ($l) => $l->price !== null)) {
            $ctx->authorize('stock.cost_edit', message: 'Себестоимость в приходе вводит админ или владелец');
        }

        $total = 0;
        foreach ($lines as $line) {
            $costKzt = $line->price === null ? 0 : Money::of((int) $line->price, $currency)->convert(Currency::KZT, $rate ?? Rate::one())->amount;
            $orig = ['currency' => $currency->value, 'amount' => $line->price, 'rate' => $rate?->toString()];

            if ($line->line_type === 'unit') {
                $unit = $this->intake->create($ctx, $line, (int) $purchase->supplier_id);
                $used = ($line->condition ?? 'new') === UnitCondition::Used->value;
                [$action, $status] = $used ? [UnitAction::ReceiveUsed, UnitStatus::Preparing] : [UnitAction::Receive, UnitStatus::InStock];
                $this->inventory->unitIn($ctx, $unit, $store, MovementKind::Purchase, $action, $status, $costKzt, $orig);
                if ($line->price === null) {
                    $unit->forceFill(['cost_unknown' => true])->save();
                }
                $line->forceFill(['stock_unit_id' => $unit->id, 'cost_kzt' => $costKzt, 'status_after' => $status->value])->save();
                $total += (int) ($line->price ?? 0);
            } else {
                $this->inventory->accessoryIn($ctx, (int) $line->accessory_id, $store, (int) $line->qty, $costKzt, MovementKind::Purchase, $orig);
                $line->forceFill(['cost_kzt' => $costKzt])->save();
                $total += (int) $line->price * (int) $line->qty;
            }
        }

        // immediate payments (TZ 02 §6.2 №1, №3)
        $covered = 0;
        foreach (PurchasePayment::query()->where('document_id', $document->id)->orderBy('id')->get() as $payment) {
            $account = $this->money->account((int) $payment->account_id);
            $this->money->record($ctx, $account->id, -(int) $payment->amount, ArticleCode::SupplierPayment, [
                'counterparty_type' => CounterpartyType::Supplier->value, 'counterparty_id' => $purchase->supplier_id,
            ]);
            $part = $account->currency === $currency
                ? (int) $payment->amount
                : Money::of((int) $payment->amount, $account->currency)->convert($currency, $ctx->requireRate())->amount;
            $payment->forceFill(['covered_amount' => $part])->save();
            $covered += $part;
        }
        if ($covered > $total) {
            throw PostingException::because('overpaid', 'Оплата больше суммы прихода: '.Money::of($covered, $currency)->format().' > '.Money::of($total, $currency)->format());
        }

        // the rest is supplier debt (TZ 02 §6.2 №2, №3)
        $credit = $total - $covered;
        if ($credit > 0) {
            if (! $purchase->due_date) {
                throw PostingException::because('due_date_required', 'Не оплачено '.Money::of($credit, $currency)->format().': укажите срок оплаты поставщику или оплату');
            }
            $ctx->authorize('stock.receive_on_credit', message: 'Приход в долг поставщику оформляет админ или владелец');
            $this->debts->record($ctx, CounterpartyType::Supplier, (int) $purchase->supplier_id, $currency, -$credit, DebtKind::PurchaseCredit, $purchase->due_date, $rate);
        }

        $purchase->forceFill(['total' => $total, 'credit_amount' => $credit])->save();
    }

    private function purchase(Document $document): Purchase
    {
        return Purchase::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных прихода');
    }

    /** @return Collection<int, PurchaseLine> */
    private function lines(Document $document)
    {
        return PurchaseLine::query()->where('document_id', $document->id)->orderBy('line_no')->orderBy('id')->get();
    }
}
