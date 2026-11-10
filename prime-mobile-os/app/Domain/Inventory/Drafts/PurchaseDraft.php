<?php

namespace App\Domain\Inventory\Drafts;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\PurchaseLine;
use App\Domain\Inventory\Models\PurchasePayment;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Tenancy\Models\Store;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Purchase draft builder:
 *
 *   PurchaseDraft::make($store, $dubaiMobile, Currency::USD)
 *       ->unit($variant, '356938124471206', Money::usd(1140), ['sale_price' => Money::kzt(649000)])
 *       ->accessory($glass, 50, Money::usd('1.20'))
 *       ->payFrom($safeUsd, Money::usd(1000))
 *       ->onCredit(now()->addDays(30))   // the rest becomes supplier debt in USD
 *       ->post($admin);
 *
 * Unit attributes: imei2, serial, condition (new|used), grade, battery_percent, year, defects, sale_price (Money), show_on_site.
 */
final class PurchaseDraft extends DraftBuilder
{
    private int $supplierId;

    private Currency $currency;

    private ?string $dueDate = null;

    private ?string $invoiceNo = null;

    /** @var list<array<string, mixed>> */
    private array $lines = [];

    /** @var list<array{account_id: int, amount: int}> */
    private array $payments = [];

    public static function make(Store|int $store, Supplier|int $supplier, Currency $currency = Currency::KZT): self
    {
        $draft = new self;
        $draft->storeId = self::storeId($store);
        $draft->supplierId = $supplier instanceof Supplier ? $supplier->id : $supplier;
        $draft->currency = $currency;

        return $draft;
    }

    protected function type(): DocumentType
    {
        return DocumentType::Purchase;
    }

    public function unit(Variant|int $variant, ?string $imei1, ?Money $price, array $attributes = []): self
    {
        $this->lines[] = $this->unitRow($variant, $price) + ['imei1' => $imei1] + $this->unitAttributes($attributes);

        return $this;
    }

    /** $price null — a seller's intake without the purchase price: such accessories do not sell until it is entered. */
    public function accessory(Accessory|int $accessory, int $qty, ?Money $price): self
    {
        $this->lines[] = [
            'line_type' => 'accessory',
            'accessory_id' => $accessory instanceof Accessory ? $accessory->id : $accessory,
            'qty' => $qty,
            'price' => $price === null ? null : $this->amount($price),
        ];

        return $this;
    }

    public function payFrom(MoneyAccount|int $account, Money $amount): self
    {
        $this->payments[] = ['account_id' => $account instanceof MoneyAccount ? $account->id : $account, 'amount' => $amount->amount];

        return $this;
    }

    public function onCredit(CarbonInterface $dueDate): self
    {
        $this->dueDate = $dueDate->format('Y-m-d');

        return $this;
    }

    public function invoice(string $number): self
    {
        $this->invoiceNo = $number;

        return $this;
    }

    protected function writeDetails(Document $document): void
    {
        Purchase::query()->create([
            'document_id' => $document->id, 'supplier_id' => $this->supplierId, 'currency' => $this->currency->value,
            'due_date' => $this->dueDate, 'invoice_no' => $this->invoiceNo,
        ]);
        foreach ($this->lines as $i => $line) {
            PurchaseLine::query()->create(['document_id' => $document->id, 'line_no' => $i + 1] + $line);
        }
        foreach ($this->payments as $payment) {
            PurchasePayment::query()->create(['document_id' => $document->id] + $payment);
        }
    }

    private function unitRow(Variant|int $variant, ?Money $price): array
    {
        return [
            'line_type' => 'unit',
            'variant_id' => $variant instanceof Variant ? $variant->id : $variant,
            'qty' => 1,
            'price' => $price ? $this->amount($price) : null,
        ];
    }

    private function unitAttributes(array $a): array
    {
        if (isset($a['sale_price']) && $a['sale_price'] instanceof Money) {
            $a['sale_price'] = self::kzt($a['sale_price']);
        }

        return array_intersect_key($a, array_flip(['imei2', 'serial', 'condition', 'grade', 'battery_percent', 'year', 'defects', 'sale_price', 'show_on_site']));
    }

    private function amount(Money $price): int
    {
        if ($price->currency !== $this->currency) {
            throw new InvalidArgumentException("Purchase price must be in {$this->currency->value}.");
        }

        return $price->amount;
    }
}
