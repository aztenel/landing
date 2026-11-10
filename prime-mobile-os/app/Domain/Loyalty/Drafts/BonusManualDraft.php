<?php

namespace App\Domain\Loyalty\Drafts;

use App\Domain\Counterparties\Models\Customer;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Domain\Loyalty\Models\BonusManualLine;

/**
 * «Бонусы вручную» БНС: подарок, компенсация (+) или списание (−), с причиной.
 *
 *   BonusManualDraft::make()->give($erlan, 2000, 'долго ждал заказ')->take($asxat, 500, 'ошибка')->post($admin); // L17
 */
final class BonusManualDraft extends DraftBuilder
{
    private array $lines = [];

    public static function make(): self
    {
        return new self;
    }

    public function give(Customer|int $customer, int $bonuses, string $comment): self
    {
        $this->lines[] = ['customer_id' => $customer instanceof Customer ? $customer->id : $customer, 'amount' => abs($bonuses), 'comment' => $comment, 'kind' => 'manual'];

        return $this;
    }

    public function take(Customer|int $customer, int $bonuses, string $comment): self
    {
        $this->lines[] = ['customer_id' => $customer instanceof Customer ? $customer->id : $customer, 'amount' => -abs($bonuses), 'comment' => $comment, 'kind' => 'manual'];

        return $this;
    }

    protected function type(): DocumentType
    {
        return DocumentType::BonusManual;
    }

    protected function writeDetails(Document $document): void
    {
        foreach ($this->lines as $line) {
            BonusManualLine::query()->create(['document_id' => $document->id] + $line);
        }
    }
}
