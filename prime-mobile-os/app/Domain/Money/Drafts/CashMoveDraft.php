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
 * «Движение денег» ДДС:
 *
 *   CashMoveDraft::collection($store, $cashKzt, Money::kzt(600000))->post($seller);        // отправить выручку
 *   CashMoveDraft::acceptCollection($store, $sentDoc, Money::kzt(598000))->post($owner);   // принять другую сумму
 *   CashMoveDraft::transfer($store, $kaspiGold, $safeKzt, Money::kzt(100000))->post($admin);
 *   CashMoveDraft::deposit($store, $cashKzt, Money::kzt(50000))->post($owner);  ::withdrawal(...) — только владелец
 */
final class CashMoveDraft extends DraftBuilder
{
    private array $row = [];

    private static function make(Store|int $store, string $kind, Money $amount, array $row = []): self
    {
        $d = new self;
        $d->storeId = self::storeId($store);
        $d->row = ['kind' => $kind, 'amount' => $amount->amount] + $row;

        return $d;
    }

    private static function id(MoneyAccount|int $account): int
    {
        return $account instanceof MoneyAccount ? $account->id : $account;
    }

    public static function deposit(Store|int $store, MoneyAccount|int $to, Money $amount): self
    {
        return self::make($store, 'deposit', $amount, ['to_account_id' => self::id($to)]);
    }

    public static function withdrawal(Store|int $store, MoneyAccount|int $from, Money $amount): self
    {
        return self::make($store, 'withdrawal', $amount, ['from_account_id' => self::id($from)]);
    }

    public static function transfer(Store|int $store, MoneyAccount|int $from, MoneyAccount|int $to, Money $amount, ?Money $fee = null): self
    {
        return self::make($store, 'transfer', $amount, ['from_account_id' => self::id($from), 'to_account_id' => self::id($to), 'fee_amount' => $fee?->amount ?? 0]);
    }

    public static function collection(Store|int $store, MoneyAccount|int $cash, Money $amount): self
    {
        return self::make($store, 'collection', $amount, ['from_account_id' => self::id($cash)]);
    }

    /** $received: what the owner counted; $toOwner — «Забрал себе» instead of the safe. */
    public static function acceptCollection(Store|int $store, Document|int $sent, Money $received, bool $toOwner = false): self
    {
        return self::make($store, 'collection_accept', $received, [
            'collection_document_id' => $sent instanceof Document ? $sent->id : $sent, 'target' => $toOwner ? 'owner' : 'safe',
        ]);
    }

    public function note(string $text): self
    {
        $this->row['description'] = $text;

        return $this;
    }

    protected function type(): DocumentType
    {
        return DocumentType::CashMove;
    }

    protected function writeDetails(Document $document): void
    {
        CashMove::query()->create(['document_id' => $document->id] + $this->row);
    }
}
