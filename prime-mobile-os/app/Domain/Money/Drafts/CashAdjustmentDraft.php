<?php

namespace App\Domain\Money\Drafts;

use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\CashMove;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Tenancy\Models\Store;
use App\Support\Money\Money;

/**
 * «Расхождение кассы» РКС, one document per register:
 *
 *   CashAdjustmentDraft::make($store, $cashKzt, Money::kztMinor(-50000), 'Сдача покупателю')->post($admin); // вручную, L16
 *   ShiftCash::open() / close() create them with the phase themselves.
 */
final class CashAdjustmentDraft extends DraftBuilder
{
    private array $row = [];

    public static function make(Store|int $store, MoneyAccount|int $account, Money $difference, string $comment, ?string $phase = null): self
    {
        $d = new self;
        $d->storeId = self::storeId($store);
        $d->row = [
            'kind' => 'adjustment', 'amount' => $difference->amount, 'description' => $comment, 'shift_phase' => $phase,
            'to_account_id' => $account instanceof MoneyAccount ? $account->id : $account,
        ];

        return $d;
    }

    protected function type(): DocumentType
    {
        return DocumentType::CashAdjustment;
    }

    protected function writeDetails(Document $document): void
    {
        CashMove::query()->create(['document_id' => $document->id] + $this->row);
    }
}
