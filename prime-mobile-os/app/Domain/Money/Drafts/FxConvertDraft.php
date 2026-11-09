<?php

namespace App\Domain\Money\Drafts;

use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\FxConversion;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Tenancy\Models\Store;
use App\Support\Money\Money;

/**
 *   FxConvertDraft::make($safeUsd, Money::usd(1000), $safeKzt, Money::kzt(512000))->post($admin);
 */
final class FxConvertDraft extends DraftBuilder
{
    private array $row;

    public static function make(MoneyAccount|int $from, Money $fromAmount, MoneyAccount|int $to, Money $toAmount, Store|int|null $store = null): self
    {
        $draft = new self;
        $draft->storeId = self::storeId($store);
        $draft->row = [
            'from_account_id' => $from instanceof MoneyAccount ? $from->id : $from, 'from_amount' => $fromAmount->amount,
            'to_account_id' => $to instanceof MoneyAccount ? $to->id : $to, 'to_amount' => $toAmount->amount,
        ];

        return $draft;
    }

    protected function type(): DocumentType
    {
        return DocumentType::FxConvert;
    }

    protected function writeDetails(Document $document): void
    {
        FxConversion::query()->create(['document_id' => $document->id] + $this->row);
    }
}
