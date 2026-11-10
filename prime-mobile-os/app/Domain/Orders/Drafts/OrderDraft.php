<?php

namespace App\Domain\Orders\Drafts;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Money\Money;

/**
 * «Заказ (бронь)» ЗАК:
 *
 *   OrderDraft::unit($store, $seller, $asel, $unit, Money::kzt(699000))->prepay('kaspi_qr', Money::kzt(50000))->post($seller);
 *   OrderDraft::variant($store, $seller, $asel, $variant, Money::kzt(699000))->post($seller);   // «под заказ», без телефона
 */
final class OrderDraft extends DraftBuilder
{
    private array $row;

    public static function unit(Store|int $store, User $seller, Customer $customer, StockUnit $unit, Money $price): self
    {
        return self::make($store, $seller, $customer, $price, ['kind' => 'unit', 'stock_unit_id' => $unit->id, 'variant_id' => $unit->variant_id]);
    }

    public static function variant(Store|int $store, User $seller, Customer $customer, Variant|int $variant, Money $price): self
    {
        return self::make($store, $seller, $customer, $price, ['kind' => 'variant', 'variant_id' => $variant instanceof Variant ? $variant->id : $variant]);
    }

    private static function make(Store|int $store, User $seller, Customer $customer, Money $price, array $row): self
    {
        $d = new self;
        $d->storeId = self::storeId($store);
        $d->row = $row + ['customer_id' => $customer->id, 'seller_id' => $seller->id, 'price' => self::kzt($price), 'hold_until' => now()];

        return $d;
    }

    public function prepay(PaymentMethodCode|string $method, Money $amount): self
    {
        $this->row['prepayment'] = self::kzt($amount);
        $this->row['prepayment_method'] = $method instanceof PaymentMethodCode ? $method->value : $method;
        // the handler takes the account from the firm settings again at posting; this only fills the draft
        $this->row['prepayment_account_id'] = PaymentMethod::resolve($this->row['prepayment_method'], $this->storeId)?->account_id;

        return $this;
    }

    public function note(string $text): self
    {
        $this->row['comment'] = $text;

        return $this;
    }

    protected function type(): DocumentType
    {
        return DocumentType::Order;
    }

    protected function writeDetails(Document $document): void
    {
        Order::query()->create(['document_id' => $document->id] + $this->row);
    }
}
