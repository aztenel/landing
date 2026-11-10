<?php

namespace App\Domain\TradeIn\Drafts;

use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\TradeIn\Models\PrepCost;
use App\Support\Money\Money;
use Carbon\CarbonInterface;

/**
 * «Затраты на подготовку» ЗПД (TZ 01 П6, 02 §4): adds to the cost of a used phone.
 *
 *   PrepCostDraft::paid($unit, 'battery', Money::kzt(18000), $cashKzt)->post($admin);
 *   PrepCostDraft::owed($unit, 'screen', Money::kzt(35000), $master, now()->addDays(14))->post($admin);
 */
final class PrepCostDraft extends DraftBuilder
{
    private array $row;

    public static function paid(StockUnit $unit, string $kind, Money $amount, MoneyAccount|int $account, ?string $note = null): self
    {
        return self::make($unit, $kind, $amount, ['account_id' => $account instanceof MoneyAccount ? $account->id : $account, 'description' => $note]);
    }

    public static function owed(StockUnit $unit, string $kind, Money $amount, Supplier|int $master, CarbonInterface $due, ?string $note = null): self
    {
        return self::make($unit, $kind, $amount, ['supplier_id' => $master instanceof Supplier ? $master->id : $master, 'due_date' => $due->format('Y-m-d'), 'description' => $note]);
    }

    private static function make(StockUnit $unit, string $kind, Money $amount, array $row): self
    {
        $d = new self;
        $d->storeId = $unit->location_type === 'store' ? (int) $unit->location_id : null;
        $d->row = ['stock_unit_id' => $unit->id, 'kind' => $kind, 'amount' => self::kzt($amount)] + $row;

        return $d;
    }

    protected function type(): DocumentType
    {
        return DocumentType::PrepCost;
    }

    protected function writeDetails(Document $document): void
    {
        PrepCost::query()->create(['document_id' => $document->id] + $this->row);
    }
}
