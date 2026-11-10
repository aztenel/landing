<?php

namespace App\Domain\Sales\Drafts;

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\CustomerReturnLine;
use App\Domain\Sales\Models\CustomerReturnPayment;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Tenancy\Models\Store;
use App\Support\Money\Money;

/**
 * Customer return builder:
 *
 *   CustomerReturnDraft::make($saleDocument)
 *       ->line($caseLine, qty: 1)
 *       ->refund('cash', Money::kzt(8952))
 *       ->reason('Не подошёл')->post($admin);
 *
 * Use ReturnCalculator::quote($draft) to know the refund before adding refund rows.
 */
final class CustomerReturnDraft extends DraftBuilder
{
    private Document $sale;

    /** @var list<array<string, mixed>> */
    private array $lines = [];

    /** @var list<array<string, mixed>> */
    private array $refunds = [];

    public static function make(Document $saleDocument, Store|int|null $store = null): self
    {
        $draft = new self;
        $draft->sale = $saleDocument;
        $draft->storeId = self::storeId($store) ?? $saleDocument->store_id;

        return $draft;
    }

    protected function type(): DocumentType
    {
        return DocumentType::CustomerReturn;
    }

    public function line(SaleLine|int $saleLine, int $qty = 1, UnitStatus|string $unitStatusAfter = UnitStatus::InStock, ?Money $amount = null): self
    {
        $this->lines[] = [
            'sale_line_id' => $saleLine instanceof SaleLine ? $saleLine->id : $saleLine,
            'qty' => $qty,
            'unit_status_after' => $unitStatusAfter instanceof UnitStatus ? $unitStatusAfter->value : $unitStatusAfter,
            'amount' => $amount ? self::kzt($amount) : null,
        ];

        return $this;
    }

    public function refund(PaymentMethodCode|string $method, Money $amount, ?int $accountId = null): self
    {
        $method = $method instanceof PaymentMethodCode ? $method : PaymentMethodCode::from($method);
        $this->refunds[] = [
            'method' => $method->value,
            'account_id' => $method === PaymentMethodCode::Debt ? null : ($accountId ?? PaymentMethod::resolve($method, $this->storeId)?->account_id),
            'amount' => self::kzt($amount),
        ];

        return $this;
    }

    public function refundToDebt(Money $amount): self
    {
        return $this->refund(PaymentMethodCode::Debt, $amount);
    }

    /** Exchange: this part goes into the new sale (Exchange::post, audit №34). */
    public function refundToExchange(Money $amount): self
    {
        return $this->refund(PaymentMethodCode::ExchangeOffset, $amount);
    }

    protected function writeDetails(Document $document): void
    {
        CustomerReturn::query()->create([
            'document_id' => $document->id,
            'sale_document_id' => $this->sale->id,
            'customer_id' => Sale::query()->where('document_id', $this->sale->id)->value('customer_id'),
        ]);
        foreach ($this->lines as $line) {
            CustomerReturnLine::query()->create(['document_id' => $document->id] + $line);
        }
        foreach ($this->refunds as $refund) {
            CustomerReturnPayment::query()->create(['document_id' => $document->id] + $refund);
        }
    }
}
