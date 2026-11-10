<?php

namespace App\Domain\TradeIn\Drafts;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Tenancy\Models\Store;
use App\Domain\TradeIn\Models\TradeInAct;
use App\Domain\TradeIn\Models\TradeInPayout;
use App\Support\Money\Money;

/**
 * Акт trade-in ТРД:
 *
 *   TradeInDraft::make($store, $customer, $variant, '356938…', Money::kzt(150000))
 *       ->grade('B')->battery(84)->checklist(['icloud' => 'yes', …])->kit(['box'])->imeiCheck('registered')
 *       ->offset(Money::kzt(7980))->payout('cash', Money::kzt(142020))->idDocument('012345678')->save();
 *
 * offset + payouts = amount. The offset goes into a check posted in the same operation (CartService / Poster::postMany).
 */
final class TradeInDraft extends DraftBuilder
{
    private array $act;

    private array $payouts = [];

    public static function make(Store|int $store, Customer|int $customer, Variant|int $variant, ?string $imei1, Money $amount): self
    {
        $d = new self;
        $d->storeId = self::storeId($store);
        $d->act = [
            'customer_id' => $customer instanceof Customer ? $customer->id : $customer,
            'variant_id' => $variant instanceof Variant ? $variant->id : $variant,
            'imei1' => $imei1, 'amount' => self::kzt($amount), 'grade' => 'B', 'checklist' => [], 'imei_verified' => true,
        ];

        return $d;
    }

    public function imei2(?string $imei2): self
    {
        $this->act['imei2'] = $imei2;

        return $this;
    }

    public function serial(?string $serial): self
    {
        $this->act['serial'] = $serial;

        return $this;
    }

    public function grade(string $grade): self
    {
        $this->act['grade'] = $grade;

        return $this;
    }

    public function battery(?int $percent): self
    {
        $this->act['battery_percent'] = $percent;

        return $this;
    }

    /** @param array<string, string> $checklist item => yes|no|skip */
    public function checklist(array $checklist): self
    {
        $this->act['checklist'] = $checklist;

        return $this;
    }

    public function replaced(array $parts): self
    {
        $this->act['replaced'] = array_values($parts);

        return $this;
    }

    public function kit(array $kit): self
    {
        $this->act['kit'] = array_values($kit);

        return $this;
    }

    public function imeiCheck(?string $result, bool $verified = true): self
    {
        $this->act['imei_check'] = $result;
        $this->act['imei_verified'] = $verified;

        return $this;
    }

    public function gridPrice(?int $tiyn): self
    {
        $this->act['grid_price'] = $tiyn;

        return $this;
    }

    public function idDocument(?string $number): self
    {
        $this->act['id_document_no'] = $number;

        return $this;
    }

    public function offset(Money $amount): self
    {
        $this->act['offset_amount'] = self::kzt($amount);

        return $this;
    }

    public function payout(PaymentMethodCode|string $method, Money $amount, ?string $reference = null): self
    {
        $this->payouts[] = ['method' => $method instanceof PaymentMethodCode ? $method->value : $method, 'amount' => self::kzt($amount), 'reference' => $reference];

        return $this;
    }

    protected function type(): DocumentType
    {
        return DocumentType::TradeIn;
    }

    protected function writeDetails(Document $document): void
    {
        TradeInAct::query()->create(['document_id' => $document->id] + $this->act);
        foreach ($this->payouts as $p) {
            TradeInPayout::query()->create(['document_id' => $document->id] + $p);
        }
    }
}
