<?php

namespace App\Domain\Sales\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Ledger\Enums\BonusKind;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\MovementKind;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\BonusEntry;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\InventoryLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\CustomerReturnLine;
use App\Domain\Sales\Models\CustomerReturnPayment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Services\ReturnCalculator;
use App\Domain\Tenancy\Access\Limit;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rounding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Возврат покупателя, full or partial (TZ 02 §6.2 №20, §6.5): goods back at the cost of that sale (phones keep
 * their own cost and go to in_stock / preparing / on_check / defective; accessories recompute the average);
 * refund from accounts or by reducing the customer's debt; accrued bonuses of the returned lines are cancelled,
 * bonuses spent on them are given back «the same» (with their expiry). If cancelled bonuses were already spent,
 * the shortfall is withheld from the money refund (assumption of §6.5). Bank commission is not refunded.
 */
class CustomerReturnHandler extends AbstractDocumentHandler
{
    public function __construct(
        private readonly InventoryLedger $inventory,
        private readonly MoneyLedger $money,
        private readonly DebtLedger $debts,
        private readonly BonusLedger $bonuses,
        private readonly ReturnCalculator $calculator,
    ) {}

    public function type(): DocumentType
    {
        return DocumentType::CustomerReturn;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $ctx->requireStoreId();
        $header = $this->header($document);
        // FOR SHARE (documents come before stock rows in the lock order): a concurrent reversal of the sale waits for
        // this return, and this return sees the sale status as committed — no return of a reversed sale
        $saleDoc = Document::query()->whereKey($header->sale_document_id)->sharedLock()->first();
        if (! $saleDoc || $saleDoc->type !== DocumentType::Sale) {
            throw PostingException::because('sale_not_found', 'Продажа для возврата не найдена');
        }
        if ($saleDoc->status !== DocumentStatus::Posted) {
            throw PostingException::because('sale_not_posted', "Продажа {$saleDoc->number} не проведена или сторнирована");
        }
        // refund to debt and bonuses go to the header customer: it must be the customer of that sale (TZ 03 §18 p.4)
        $saleCustomerId = Sale::query()->where('document_id', $saleDoc->id)->value('customer_id');
        if ((int) $header->customer_id !== (int) $saleCustomerId) {
            throw PostingException::because('customer_mismatch', 'Клиент возврата не совпадает с клиентом продажи');
        }
        $lines = $this->lines($document);
        if ($lines->isEmpty()) {
            throw PostingException::because('empty', 'Выберите, что возвращают');
        }
        $seen = [];
        foreach ($lines as $line) {
            if (isset($seen[$line->sale_line_id])) {
                throw PostingException::because('duplicate_line', 'Одна строка чека дважды в возврате');
            }
            $seen[$line->sale_line_id] = true;
            $saleLine = SaleLine::query()->find($line->sale_line_id);
            if (! $saleLine || (int) $saleLine->document_id !== $saleDoc->id) {
                throw PostingException::because('bad_line', 'Строка не из этой продажи');
            }
            if ($line->qty < 1 || $line->qty > $saleLine->qty - $saleLine->returned_qty) {
                throw PostingException::because('bad_qty', 'Возвращают больше, чем осталось в чеке');
            }
            if ($saleLine->stock_unit_id) {
                $status = UnitStatus::tryFrom((string) ($line->unit_status_after ?? 'in_stock'));
                if (! in_array($status, [UnitStatus::InStock, UnitStatus::Preparing, UnitStatus::OnCheck, UnitStatus::Defective], true)) {
                    throw PostingException::because('bad_status', 'Состояние возвращённого телефона: целый, на подготовку, на проверку или брак');
                }
            }
            if ($line->amount !== null && ($line->amount < 0 || $line->amount % Money::TENGE !== 0)) {
                throw PostingException::because('bad_amount', 'Сумма возврата строки — целые тенге');
            }
        }
        foreach ($this->refunds($document) as $refund) {
            $method = PaymentMethodCode::tryFrom($refund->method);
            if (! $method || ! ($method->isMoney() || $method === PaymentMethodCode::Debt)) {
                throw PostingException::because('bad_refund', 'Вернуть можно деньгами на счёт или уменьшением долга клиента');
            }
            if ($method->isMoney() && ! $refund->account_id) {
                throw PostingException::because('no_account', 'Укажите счёт возврата');
            }
            // refund amounts are tenge: a $ account would get tiyn written as cents
            if ($method->isMoney() && $this->money->account((int) $refund->account_id)->currency !== Currency::KZT) {
                throw PostingException::because('kzt_only', 'Возврат покупателю — только в тенге');
            }
            if ($refund->amount <= 0 || $refund->amount % Money::TENGE !== 0) {
                throw PostingException::because('bad_amount', 'Сумма возврата — целые тенге больше нуля');
            }
            if ($method === PaymentMethodCode::Debt && ! $header->customer_id) {
                throw PostingException::because('customer_required', 'Уменьшить долг можно только у клиента');
            }
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $plan = new LockPlan;
        $store = Location::store($ctx->requireStoreId());
        $header = $this->header($document);
        foreach ($this->lines($document) as $line) {
            $saleLine = SaleLine::query()->find($line->sale_line_id);
            $saleLine->stock_unit_id
                ? $plan->unit((int) $saleLine->stock_unit_id)
                : $plan->accessory((int) $saleLine->accessory_id, $store);
            if ((int) $saleLine->debt_share > 0 && $header->customer_id) {
                // the unpaid credit part is checked against the customer debt under the lock (TZ 02 №20, Л)
                $plan->counterparty(CounterpartyType::Customer, (int) $header->customer_id, Currency::KZT);
            }
        }
        foreach ($this->refunds($document) as $refund) {
            $plan->account($refund->account_id ? (int) $refund->account_id : null);
            if ($refund->method === PaymentMethodCode::Debt->value) {
                $plan->counterparty(CounterpartyType::Customer, (int) $header->customer_id, Currency::KZT);
            }
        }
        $plan->bonus($header->customer_id ? (int) $header->customer_id : null);

        return $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $header = $this->header($document);
        $saleDoc = Document::query()->findOrFail($header->sale_document_id);
        $store = Location::store($ctx->requireStoreId());
        $lines = $this->lines($document);
        $customerId = $header->customer_id ? (int) $header->customer_id : null;

        // amounts and permissions (TZ 03 §5.1 L7–L9)
        $phones = 0;
        $accessories = 0;
        $prepared = [];
        foreach ($lines as $line) {
            $saleLine = SaleLine::query()->findOrFail($line->sale_line_id);
            $amount = $this->calculator->lineAmount($saleLine, (int) $line->qty, $line->amount);
            if ($amount > $this->calculator->remainingAmount($saleLine)) {
                throw PostingException::because('refund_over_line', 'Сумма возврата строки больше, чем за неё заплатили: не больше '
                    .Money::kztMinor($this->calculator->remainingAmount($saleLine))->format());
            }
            $prepared[] = [$line, $saleLine, $amount];
            $saleLine->stock_unit_id ? $phones += $amount : $accessories += $amount;
        }
        $days = (int) abs(CarbonImmutable::parse($saleDoc->occurred_at)->startOfDay()->diffInDays($ctx->occurredAt()->startOfDay()));
        if ($phones > 0) {
            $ctx->authorize('return.phone', Limit::ReturnPhone, $phones, 'return');
            $ctx->authorize('return.phone', Limit::ReturnDays, $days, 'return');
        }
        if ($accessories > 0 || $phones === 0) {
            $ctx->authorize('return.accessory', Limit::ReturnAccessory, $accessories, 'return');
            $ctx->authorize('return.accessory', Limit::ReturnDays, $days, 'return');
        }

        // goods back
        $restoreBonuses = 0;
        $cancelBonuses = 0;
        $creditPart = 0;
        $cancelSources = [];
        foreach ($prepared as [$line, $saleLine, $amount]) {
            $qty = (int) $line->qty;
            // re-check under the locks (stock rows serialize returns of the same line): another return may have been
            // posted since validate() — never return more than is left in the check
            if ($qty > (int) $saleLine->qty - (int) $saleLine->returned_qty) {
                throw PostingException::because('bad_qty', 'Возвращают больше, чем осталось в чеке (часть уже возвращена)');
            }
            $restoreBonuses += $this->calculator->bonusesToRestore($saleLine, $qty);
            $cancelBonuses += $this->calculator->bonusesToCancel($saleLine, $qty);
            $creditPart += $this->calculator->creditPart($saleLine, $qty);
            if ($saleLine->stock_unit_id) {
                $unit = $ctx->locks->unit((int) $saleLine->stock_unit_id);
                if (! $saleLine->is_active) {
                    throw PostingException::because('line_inactive', 'Эта продажа телефона уже возвращена или сторнирована');
                }
                $status = UnitStatus::from($line->unit_status_after ?? 'in_stock');
                $this->inventory->unitIn($ctx, $unit, $store, MovementKind::ReturnIn, UnitAction::CustomerReturn, $status);
                $cost = (int) $unit->cost_kzt;
            } else {
                $unitCost = Rounding::HalfUp->divide((int) $saleLine->cost_kzt, (int) $saleLine->qty);
                $this->inventory->accessoryIn($ctx, (int) $saleLine->accessory_id, $store, $qty, $unitCost, MovementKind::ReturnIn);
                $cost = $unitCost * $qty;
            }
            $returned = (int) $saleLine->returned_qty + $qty;
            $saleLine->forceFill(['returned_qty' => $returned, 'is_active' => $returned < (int) $saleLine->qty])->save();
            $line->forceFill(['amount' => $amount, 'cost_kzt' => $cost])->save();

            if ($saleLine->bonus_accrued > 0) {
                $cancelSources[] = $saleLine->id;
            }
        }

        // bonuses: give back the spent ones, cancel the accrued ones
        $withheld = 0;
        if ($customerId && $restoreBonuses > 0) {
            $spend = BonusEntry::query()->where('document_id', $saleDoc->id)->where('amount', '<', 0)->where('kind', BonusKind::Spend->value)->first();
            if ($spend) {
                $this->bonuses->restore($ctx, $spend, $restoreBonuses);
            }
        }
        if ($customerId && $cancelBonuses > 0) {
            $sourceIds = BonusEntry::query()->where('document_id', $saleDoc->id)->where('is_source', true)
                ->whereIn('sale_line_id', $cancelSources)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $result = $this->bonuses->consume($ctx, $customerId, $cancelBonuses, BonusKind::CancelAccrual, $sourceIds, includePending: true, allowShortfall: true);
            $withheld = $result['shortfall'] * Money::TENGE;
        }

        // refund
        $total = array_sum(array_map(fn ($p) => $p[2], $prepared));
        $required = $total - $restoreBonuses * Money::TENGE - $withheld;
        $refunds = $this->refunds($document);
        $refunded = (int) $refunds->sum('amount');
        if ($refunded !== $required) {
            $details = $restoreBonuses ? ' (бонусами возвращено '.$restoreBonuses.')' : '';
            $details .= $withheld ? ', удержано за потраченные бонусы '.Money::kztMinor($withheld)->format() : '';
            throw PostingException::because('refund_mismatch', 'Сумма возврата должна быть '.Money::kztMinor($required)->format().$details, [
                'required' => $required, 'given' => $refunded, 'withheld' => $withheld, 'bonuses_returned' => $restoreBonuses,
            ]);
        }
        // the part sold on credit and not paid yet is refunded by reducing the debt, never in money (TZ 02 №20, Л):
        // otherwise a phone taken on credit and returned would be «refunded» in cash while the debt stays
        if ($customerId && $creditPart > 0) {
            $owed = max(0, (int) $ctx->locks->counterparty(CounterpartyType::Customer, $customerId, Currency::KZT)->balance);
            $unpaid = min($creditPart, $owed);
            $toDebt = (int) $refunds->where('method', PaymentMethodCode::Debt->value)->sum('amount');
            if ($toDebt < $unpaid) {
                throw PostingException::because('refund_to_debt_required', 'Продажа была в долг: уменьшите долг клиента на '
                    .Money::kztMinor($unpaid)->format().', деньгами — только оплаченная часть', ['unpaid' => $unpaid, 'to_debt' => $toDebt]);
            }
        }
        $customerAttrs = $customerId ? ['counterparty_type' => CounterpartyType::Customer->value, 'counterparty_id' => $customerId] : [];
        foreach ($refunds as $refund) {
            if ($refund->method === PaymentMethodCode::Debt->value) {
                $this->debts->record($ctx, CounterpartyType::Customer, $customerId, Currency::KZT, -(int) $refund->amount, DebtKind::Return);
            } else {
                $this->money->record($ctx, (int) $refund->account_id, -(int) $refund->amount, ArticleCode::CustomerRefund, $customerAttrs);
            }
        }

        $header->forceFill([
            'total' => $total, 'bonus_returned' => $restoreBonuses, 'bonus_cancelled' => $cancelBonuses, 'bonus_withheld' => $withheld,
        ])->save();
    }

    /** Undo the sale-line bookkeeping of the return (the journals are reversed generically). */
    public function afterReverse(Document $original, PostingContext $ctx): void
    {
        foreach ($this->lines($original) as $line) {
            $saleLine = SaleLine::query()->findOrFail($line->sale_line_id);
            $returned = (int) $saleLine->returned_qty - (int) $line->qty;
            $saleLine->forceFill(['returned_qty' => $returned, 'is_active' => true])->save();
        }
    }

    private function header(Document $document): CustomerReturn
    {
        return CustomerReturn::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных возврата');
    }

    /** @return Collection<int, CustomerReturnLine> */
    private function lines(Document $document): Collection
    {
        return CustomerReturnLine::query()->where('document_id', $document->id)->orderBy('id')->get();
    }

    /** @return Collection<int, CustomerReturnPayment> */
    private function refunds(Document $document): Collection
    {
        return CustomerReturnPayment::query()->where('document_id', $document->id)->orderBy('id')->get();
    }
}
