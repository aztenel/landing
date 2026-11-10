<?php

namespace App\Domain\Inventory\Drafts;

use App\Domain\Inventory\Models\CostEntry;
use App\Domain\Inventory\Models\CostEntryLine;
use App\Domain\Inventory\Models\CostEntryPayment;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\StockUnit;
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
 * «Внесение себестоимости» ВСБ (audit №4):
 *
 *   CostEntryDraft::forPurchase($store, $purchaseDocument)
 *       ->price($unitA, Money::usd(1000))->price($unitB, Money::usd(1000))
 *       ->payFrom($cashUsd, Money::usd(1000))->onCredit(now()->addDays(10))
 *       ->post($admin);
 *
 *   CostEntryDraft::withoutPurchase($store, 'Лишний телефон дня X')->price($unit, Money::kzt(385000))->post($owner);
 */
final class CostEntryDraft extends DraftBuilder
{
    private ?int $purchaseDocumentId = null;

    private ?int $supplierId = null;

    private Currency $currency = Currency::KZT;

    private ?string $dueDate = null;

    /** @var list<array{stock_unit_id: int, price: int}> */
    private array $lines = [];

    /** @var list<array{account_id: int, amount: int}> */
    private array $payments = [];

    public static function forPurchase(Store|int $store, Document|int $purchaseDocument): self
    {
        $draft = new self;
        $draft->storeId = self::storeId($store);
        $draft->purchaseDocumentId = $purchaseDocument instanceof Document ? $purchaseDocument->id : $purchaseDocument;
        $purchase = Purchase::query()->where('document_id', $draft->purchaseDocumentId)->firstOrFail();
        $draft->supplierId = (int) $purchase->supplier_id;
        $draft->currency = Currency::from($purchase->currency);

        return $draft;
    }

    public static function withoutPurchase(Store|int $store, string $reason): self
    {
        $draft = new self;
        $draft->storeId = self::storeId($store);
        $draft->reasonText = $reason;

        return $draft;
    }

    protected function type(): DocumentType
    {
        return DocumentType::CostEntry;
    }

    public function price(StockUnit|int $unit, Money $price): self
    {
        if ($price->currency !== $this->currency) {
            throw new InvalidArgumentException("Price must be in {$this->currency->value}.");
        }
        $this->lines[] = ['stock_unit_id' => $unit instanceof StockUnit ? $unit->id : $unit, 'price' => $price->amount];

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

    protected function writeDetails(Document $document): void
    {
        CostEntry::query()->create([
            'document_id' => $document->id, 'purchase_document_id' => $this->purchaseDocumentId, 'supplier_id' => $this->supplierId,
            'currency' => $this->currency->value, 'due_date' => $this->dueDate, 'reason' => $this->reasonText,
        ]);
        foreach ($this->lines as $line) {
            CostEntryLine::query()->create(['document_id' => $document->id] + $line);
        }
        foreach ($this->payments as $payment) {
            CostEntryPayment::query()->create(['document_id' => $document->id] + $payment);
        }
    }
}
