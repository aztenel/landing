<?php

namespace App\Domain\Debts\Posting;

use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Debts\Models\DebtPayment;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\ArticleCode;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rounding;

/**
 * Оплата долга (TZ 02 §6.2 №33, №34, §3.5):
 *  - debt_payment_in: the customer pays us (₸): + account, − commission for non-cash, − customer debt;
 *  - debt_payment_out: we pay a supplier from a ₸ or $ account; a $ debt is reduced in $ at the average rate and the
 *    difference with the money actually paid is the exchange difference (debt_entries.fx_diff_kzt).
 * Bonus accrual on the repaid credit part (TZ 02 №34) is left to the Loyalty module.
 */
class DebtPaymentHandler extends AbstractDocumentHandler
{
    public function __construct(
        private readonly DocumentType $documentType,
        private readonly MoneyLedger $money,
        private readonly DebtLedger $debts,
    ) {}

    public static function incoming(MoneyLedger $money, DebtLedger $debts): self
    {
        return new self(DocumentType::DebtPaymentIn, $money, $debts);
    }

    public static function outgoing(MoneyLedger $money, DebtLedger $debts): self
    {
        return new self(DocumentType::DebtPaymentOut, $money, $debts);
    }

    public function type(): DocumentType
    {
        return $this->documentType;
    }

    private function isIncoming(): bool
    {
        return $this->documentType === DocumentType::DebtPaymentIn;
    }

    public function requiresExchangeRate(Document $document): bool
    {
        $p = $this->payment($document);

        return $p->debt_currency === 'USD' || $this->money->account((int) $p->account_id)->currency === Currency::USD;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $p = $this->payment($document);
        $expected = $this->isIncoming() ? 'in' : 'out';
        if ($p->direction !== $expected) {
            throw PostingException::because('bad_direction', 'Направление оплаты не совпадает с типом документа');
        }
        if ($p->amount <= 0) {
            throw PostingException::because('bad_amount', 'Сумма оплаты должна быть больше нуля');
        }
        if ($this->isIncoming()) {
            if ($p->counterparty_type !== CounterpartyType::Customer->value || $p->debt_currency !== 'KZT') {
                throw PostingException::because('bad_counterparty', 'Оплата долга клиента — только в тенге');
            }
            if ($this->money->account((int) $p->account_id)->currency !== Currency::KZT) {
                throw PostingException::because('kzt_only', 'Клиент платит только в тенге');
            }
            if ($p->amount % Money::TENGE !== 0) {
                throw PostingException::because('not_whole', 'Оплата — целые тенге');
            }
        } elseif ($p->counterparty_type !== CounterpartyType::Supplier->value) {
            throw PostingException::because('bad_counterparty', 'Оплата поставщику: выберите поставщика');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $p = $this->payment($document);

        return (new LockPlan)
            ->account((int) $p->account_id)
            ->counterparty(CounterpartyType::from($p->counterparty_type), (int) $p->counterparty_id, Currency::from($p->debt_currency));
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $p = $this->payment($document);
        $type = CounterpartyType::from($p->counterparty_type);
        $debtCurrency = Currency::from($p->debt_currency);
        $account = $this->money->account((int) $p->account_id);
        $attrs = ['counterparty_type' => $type->value, 'counterparty_id' => (int) $p->counterparty_id];

        if ($this->isIncoming()) {
            $ctx->authorize('debt.customer_pay');
            $this->money->record($ctx, $account->id, (int) $p->amount, ArticleCode::DebtIn, $attrs);
            // bank commission from the firm settings of the payment method of this account, not from the request (audit №6, №22)
            $percent = (string) (PaymentMethod::query()->where('account_id', $account->id)->where('is_enabled', true)
                ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $document->store_id))
                ->orderByRaw('store_id IS NULL')->value('commission_percent') ?? '0.00');
            $p->forceFill(['commission_percent' => $percent])->save();
            $commission = Money::kztMinor((int) $p->amount)->percent($percent, Rounding::HalfUp, Money::TENGE)->amount;
            if ($commission > 0) {
                $this->money->record($ctx, $account->id, -$commission, ArticleCode::BankFee, $attrs);
            }
            $this->debts->record($ctx, $type, (int) $p->counterparty_id, Currency::KZT, -(int) $p->amount, DebtKind::PaymentIn);
            $p->forceFill(['debt_amount' => (int) $p->amount, 'commission_amount' => $commission])->save();

            return;
        }

        $ctx->authorize('debt.supplier', message: 'Оплату поставщику проводит админ или владелец');
        $moneyEntry = $this->money->record($ctx, $account->id, -(int) $p->amount, ArticleCode::SupplierPayment, $attrs);
        // the settled debt is computed, never taken from the request (audit №22): same currency — the payment itself,
        // otherwise the payment converted at the document rate
        $debtAmount = $account->currency === $debtCurrency
            ? (int) $p->amount
            : Money::of((int) $p->amount, $account->currency)->convert($debtCurrency, $ctx->requireRate())->amount;
        $debtEntry = $this->debts->record(
            $ctx, $type, (int) $p->counterparty_id, $debtCurrency, $debtAmount, DebtKind::PaymentOut,
            settledKzt: -(int) $moneyEntry->amount_kzt,
        );
        $p->forceFill(['debt_amount' => $debtAmount, 'fx_diff_kzt' => (int) $debtEntry->fx_diff_kzt])->save();
    }

    private function payment(Document $document): DebtPayment
    {
        return DebtPayment::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных оплаты долга');
    }
}
