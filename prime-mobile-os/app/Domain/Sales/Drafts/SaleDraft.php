<?php

namespace App\Domain\Sales\Drafts;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\AccessoryPrice;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonInterface;

/**
 * Sale draft builder (cash register, storefront orders, seeders, tests):
 *
 *   SaleDraft::make($store, $seller)->customer($erlan)
 *       ->unit($iphone, Money::kzt(549000))
 *       ->accessory($case, 1, Money::kzt(9000))
 *       ->discount(Money::kzt(3000))
 *       ->pay('kaspi_qr', Money::kzt(200000))
 *       ->cash(Money::kzt(205000), tendered: Money::kzt(210000))
 *       ->post($seller);
 *
 * Accounts and commission % of payments come from payment_methods unless given explicitly.
 */
final class SaleDraft extends DraftBuilder
{
    private int $sellerId;

    private ?int $customerId = null;

    private ?int $orderDocumentId = null;

    private string $source = 'pos';

    private int $checkDiscount = 0;

    /** @var list<array<string, mixed>> */
    private array $lines = [];

    /** @var list<array<string, mixed>> */
    private array $payments = [];

    public static function make(Store|int $store, User|int $seller): self
    {
        $draft = new self;
        $draft->storeId = self::storeId($store);
        $draft->sellerId = $seller instanceof User ? $seller->id : $seller;

        return $draft;
    }

    protected function type(): DocumentType
    {
        return DocumentType::Sale;
    }

    public function customer(Customer|int|null $customer): self
    {
        $this->customerId = $customer instanceof Customer ? $customer->id : $customer;

        return $this;
    }

    public function source(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function fromOrder(int $orderDocumentId): self
    {
        $this->orderDocumentId = $orderDocumentId;

        return $this;
    }

    public function unit(StockUnit|int $unit, Money $price, ?Money $discount = null, ?Money $listPrice = null): self
    {
        $unit = $unit instanceof StockUnit ? $unit : StockUnit::query()->findOrFail($unit);
        $this->lines[] = [
            'line_type' => 'unit', 'stock_unit_id' => $unit->id, 'qty' => 1,
            'price' => self::kzt($price), 'line_discount' => $discount ? self::kzt($discount) : 0,
            'list_price_kzt' => $listPrice ? self::kzt($listPrice) : $unit->sale_price,
        ];

        return $this;
    }

    public function accessory(Accessory|int $accessory, int $qty, Money $price, ?Money $discount = null, bool $gift = false, ?Money $listPrice = null): self
    {
        $id = $accessory instanceof Accessory ? $accessory->id : $accessory;
        $this->lines[] = [
            'line_type' => 'accessory', 'accessory_id' => $id, 'qty' => $qty,
            'price' => self::kzt($price), 'line_discount' => $discount ? self::kzt($discount) : 0, 'is_gift' => $gift,
            'list_price_kzt' => $listPrice ? self::kzt($listPrice) : AccessoryPrice::query()->where('accessory_id', $id)->value('price_kzt'),
        ];

        return $this;
    }

    /** «Подарок к покупке» (price 0). */
    public function gift(Accessory|int $accessory, int $qty = 1): self
    {
        return $this->accessory($accessory, $qty, Money::kzt(0), gift: true, listPrice: Money::kzt(0));
    }

    /** Discount on the whole check (spread over lines at posting). */
    public function discount(Money $checkDiscount): self
    {
        $this->checkDiscount = self::kzt($checkDiscount);

        return $this;
    }

    /**
     * @param  array{account_id?: int, tendered?: Money, due_date?: CarbonInterface, commission_percent?: string}  $options
     */
    public function pay(PaymentMethodCode|string $method, Money $amount, array $options = []): self
    {
        $method = $method instanceof PaymentMethodCode ? $method : PaymentMethodCode::from($method);
        $this->payments[] = ['method' => $method, 'amount' => self::kzt($amount)] + $options;

        return $this;
    }

    public function cash(Money $amount, ?Money $tendered = null): self
    {
        return $this->pay(PaymentMethodCode::Cash, $amount, $tendered ? ['tendered' => $tendered] : []);
    }

    public function debt(Money $amount, CarbonInterface $dueDate): self
    {
        return $this->pay(PaymentMethodCode::Debt, $amount, ['due_date' => $dueDate]);
    }

    /** The part «в зачёт» of a trade-in act posted in the same operation (audit №40). */
    public function tradeIn(Document|int $act, Money $amount): self
    {
        return $this->pay(PaymentMethodCode::TradeIn, $amount, ['tradein_document_id' => $act instanceof Document ? $act->id : $act]);
    }

    public function bonus(Money $amount): self
    {
        return $this->pay(PaymentMethodCode::Bonus, $amount);
    }

    protected function writeDetails(Document $document): void
    {
        Sale::query()->create([
            'document_id' => $document->id,
            'customer_id' => $this->customerId,
            'seller_id' => $this->sellerId,
            'source' => $this->source,
            'order_document_id' => $this->orderDocumentId,
            'check_discount' => $this->checkDiscount,
        ]);
        foreach ($this->lines as $i => $line) {
            SaleLine::query()->create(['document_id' => $document->id, 'line_no' => $i + 1] + $line);
        }
        foreach ($this->payments as $p) {
            /** @var PaymentMethodCode $method */
            $method = $p['method'];
            $setup = PaymentMethod::resolve($method, $this->storeId);
            $accountId = $p['account_id'] ?? $setup?->account_id;
            if (($method->isMoney() || $method === PaymentMethodCode::TradeIn) && ! $accountId) {
                throw PostingException::because('method_not_configured', "Способ оплаты «{$method->label()}» не настроен");
            }
            SalePayment::query()->create([
                'document_id' => $document->id,
                'method' => $method->value,
                'account_id' => $accountId,
                'amount' => $p['amount'],
                'commission_percent' => $p['commission_percent'] ?? ($setup?->commission_percent ?? '0.00'),
                'tendered' => isset($p['tendered']) ? self::kzt($p['tendered']) : null,
                'debt_due_date' => isset($p['due_date']) ? $p['due_date']->format('Y-m-d') : null,
                'tradein_document_id' => $p['tradein_document_id'] ?? null,
            ]);
        }
    }
}
