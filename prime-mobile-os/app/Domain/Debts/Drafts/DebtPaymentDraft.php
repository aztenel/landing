<?php

namespace App\Domain\Debts\Drafts;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Debts\Models\DebtPayment;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Tenancy\Models\Store;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 *   DebtPaymentDraft::fromCustomer($asxat, $cashKzt, Money::kzt(50000), $store)->post($seller);
 *   DebtPaymentDraft::toSupplier($dubai, $cashKzt, Money::kzt(2080000), Currency::USD, Money::usd(4000))->post($admin);
 */
final class DebtPaymentDraft extends DraftBuilder
{
    private DocumentType $documentType;

    private array $row;

    public static function fromCustomer(Customer|int $customer, MoneyAccount|int $account, Money $amount, Store|int|null $store = null, string $commissionPercent = '0.00'): self
    {
        $draft = new self;
        $draft->documentType = DocumentType::DebtPaymentIn;
        $draft->storeId = self::storeId($store);
        $draft->row = [
            'direction' => 'in', 'counterparty_type' => CounterpartyType::Customer->value,
            'counterparty_id' => $customer instanceof Customer ? $customer->id : $customer,
            'account_id' => $account instanceof MoneyAccount ? $account->id : $account,
            'amount' => self::kzt($amount), 'debt_currency' => Currency::KZT->value, 'commission_percent' => $commissionPercent,
        ];

        return $draft;
    }

    /** @param Money|null $debtAmount how much of the debt (in its currency) this payment covers; null = convert by the document rate */
    public static function toSupplier(Supplier|int $supplier, MoneyAccount|int $account, Money $amount, Currency $debtCurrency = Currency::KZT, ?Money $debtAmount = null, Store|int|null $store = null): self
    {
        $draft = new self;
        $draft->documentType = DocumentType::DebtPaymentOut;
        $draft->storeId = self::storeId($store);
        $draft->row = [
            'direction' => 'out', 'counterparty_type' => CounterpartyType::Supplier->value,
            'counterparty_id' => $supplier instanceof Supplier ? $supplier->id : $supplier,
            'account_id' => $account instanceof MoneyAccount ? $account->id : $account,
            'amount' => $amount->amount, 'debt_currency' => $debtCurrency->value, 'debt_amount' => $debtAmount?->amount,
        ];

        return $draft;
    }

    protected function type(): DocumentType
    {
        return $this->documentType;
    }

    protected function writeDetails(Document $document): void
    {
        DebtPayment::query()->create(['document_id' => $document->id] + $this->row);
    }
}
