<?php

namespace App\Domain\Ledger\Drafts;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\OpeningBalanceLine;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Tenancy\Models\Store;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use Carbon\CarbonInterface;

/**
 * «Входящие остатки» (day X) builder:
 *
 *   OpeningBalanceDraft::make($store)
 *       ->unit($variant, '356938124471206', Money::usd(1140), ['supplier_id' => $dubai->id, 'sale_price' => Money::kzt(649000)])
 *       ->unit($usedVariant, '352881049032118', Money::kzt(165000), ['condition' => 'used', 'grade' => 'A', 'battery_percent' => 89])
 *       ->accessory($case, 10, Money::kzt(2000))
 *       ->money($cashKzt, Money::kzt(1240000))
 *       ->customerDebt($asxat, Money::kzt(70000), now()->addDays(10))
 *       ->supplierDebt($dubai, Money::usd(10000), now()->addDays(20), Rate::of('515'))
 *       ->bonus($erlan, 5000)
 *       ->post($owner);
 */
final class OpeningBalanceDraft extends DraftBuilder
{
    /** @var list<array<string, mixed>> */
    private array $lines = [];

    public static function make(Store|int $store): self
    {
        $draft = new self;
        $draft->storeId = self::storeId($store);

        return $draft;
    }

    protected function type(): DocumentType
    {
        return DocumentType::OpeningBalance;
    }

    /**
     * @param  Money  $cost  KZT, or USD (valued at $attributes['rate'] or the document rate)
     * @param  array  $attributes  imei2, serial, condition, grade, battery_percent, year, defects, sale_price (Money),
     *                             show_on_site, status_after (in_stock|preparing), supplier_id, rate, cost_unknown
     */
    public function unit(Variant|int $variant, ?string $imei1, Money $cost, array $attributes = []): self
    {
        $row = [
            'line_type' => 'unit',
            'variant_id' => $variant instanceof Variant ? $variant->id : $variant,
            'imei1' => $imei1,
        ];
        if ($cost->currency === Currency::USD) {
            $row += ['cost_currency' => 'USD', 'cost_amount_orig' => $cost->amount, 'cost_rate' => isset($attributes['rate']) ? Rate::of($attributes['rate'])->toString() : null];
        } else {
            $row += ['cost_currency' => 'KZT', 'unit_cost_kzt' => $cost->amount];
        }
        if (isset($attributes['sale_price']) && $attributes['sale_price'] instanceof Money) {
            $attributes['sale_price'] = self::kzt($attributes['sale_price']);
        }
        $this->lines[] = $row + array_intersect_key($attributes, array_flip([
            'imei2', 'serial', 'condition', 'grade', 'battery_percent', 'year', 'defects', 'sale_price', 'show_on_site', 'status_after', 'supplier_id', 'cost_unknown',
        ]));

        return $this;
    }

    public function accessory(Accessory|int $accessory, int $qty, Money $unitCost): self
    {
        $this->lines[] = [
            'line_type' => 'accessory', 'accessory_id' => $accessory instanceof Accessory ? $accessory->id : $accessory,
            'qty' => $qty, 'unit_cost_kzt' => self::kzt($unitCost),
        ];

        return $this;
    }

    public function money(MoneyAccount|int $account, Money $amount): self
    {
        $this->lines[] = [
            'line_type' => 'money', 'account_id' => $account instanceof MoneyAccount ? $account->id : $account,
            'currency' => $amount->currency->value, 'amount' => $amount->amount,
        ];

        return $this;
    }

    /** Signed by TZ 02 §6.1: + the counterparty owes us, − we owe. */
    public function debt(CounterpartyType $type, int $counterpartyId, Money $signedAmount, ?CarbonInterface $dueDate = null, Rate|string|null $rate = null): self
    {
        $this->lines[] = [
            'line_type' => 'debt', 'counterparty_type' => $type->value, 'counterparty_id' => $counterpartyId,
            'currency' => $signedAmount->currency->value, 'amount' => $signedAmount->amount,
            'due_date' => $dueDate?->format('Y-m-d'), 'rate' => $rate ? Rate::of($rate)->toString() : null,
        ];

        return $this;
    }

    /** The customer owes us $amount. */
    public function customerDebt(Customer|int $customer, Money $amount, ?CarbonInterface $dueDate = null): self
    {
        return $this->debt(CounterpartyType::Customer, $customer instanceof Customer ? $customer->id : $customer, $amount->abs(), $dueDate);
    }

    /** We owe the supplier $amount. */
    public function supplierDebt(Supplier|int $supplier, Money $amount, ?CarbonInterface $dueDate = null, Rate|string|null $rate = null): self
    {
        return $this->debt(CounterpartyType::Supplier, $supplier instanceof Supplier ? $supplier->id : $supplier, $amount->abs()->negate(), $dueDate, $rate);
    }

    public function bonus(Customer|int $customer, int $bonuses, ?CarbonInterface $availableFrom = null, ?CarbonInterface $expiresAt = null): self
    {
        $this->lines[] = [
            'line_type' => 'bonus', 'customer_id' => $customer instanceof Customer ? $customer->id : $customer,
            'bonus_amount' => $bonuses, 'available_from' => $availableFrom, 'expires_at' => $expiresAt,
        ];

        return $this;
    }

    protected function writeDetails(Document $document): void
    {
        foreach ($this->lines as $i => $line) {
            OpeningBalanceLine::query()->create(['document_id' => $document->id, 'line_no' => $i + 1] + $line);
        }
    }
}
