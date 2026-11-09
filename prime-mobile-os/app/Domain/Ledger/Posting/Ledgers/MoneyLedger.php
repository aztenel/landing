<?php

namespace App\Domain\Ledger\Posting\Ledgers;

use App\Domain\Ledger\Exceptions\InsufficientFunds;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Money\Models\MoneyArticle;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Tenancy\CurrentOrganization;
use LogicException;

/**
 * The ONLY writer of money_entries and account_balances. Each entry stores amount (account currency),
 * the rate of the moment and amount_kzt (TZ 02 §3). An account without allow_negative never goes below zero:
 * «В «Касса ₸ — Керуен» 30 000 ₸, не хватает 20 000 ₸».
 */
class MoneyLedger
{
    /** @var array<string, int> "org:code" => article id */
    private array $articles = [];

    /** @var array<string, MoneyAccount> "org:id" => account (the ledger is a singleton: never share rows between firms) */
    private array $accounts = [];

    /**
     * @param  int  $amount  signed, minor units of the ACCOUNT currency (+ money came in, − went out)
     * @param  array{counterparty_type?: string, counterparty_id?: int, sale_payment_id?: int, expense_payment_id?: int, comment?: string, exchange_rate?: string}  $attributes
     * @param  int|null  $amountKzt  override of the ₸ valuation (reversals, conversions at a fixed rate)
     */
    public function record(
        PostingContext $ctx,
        int $accountId,
        int $amount,
        ArticleCode|string|int $article,
        array $attributes = [],
        ?int $amountKzt = null,
        ?int $reversalOfEntryId = null,
    ): MoneyEntry {
        if ($amount === 0) {
            throw new LogicException('Money entry amount cannot be zero.');
        }
        $balance = $ctx->locks->account($accountId);
        $account = $this->account($accountId);
        if ($account->store_id !== null && ! $ctx->actorMayUseStore((int) $account->store_id)) {
            throw AccessDenied::because('access_denied', "Нет доступа к счёту «{$account->name}»: это счёт другого магазина");
        }

        if ($account->currency === Currency::KZT) {
            $rate = Rate::one();
            $kzt = $amount;
        } else {
            $rate = isset($attributes['exchange_rate']) ? Rate::of((string) $attributes['exchange_rate']) : $ctx->requireRate();
            $kzt = $amountKzt ?? Money::of($amount, $account->currency)->convert(Currency::KZT, $rate)->amount;
        }
        unset($attributes['exchange_rate']);

        $newBalance = (int) $balance->balance + $amount;
        if (! $balance->allow_negative && $newBalance < 0) {
            $have = Money::of((int) $balance->balance, $account->currency);
            $missing = Money::of(-$newBalance, $account->currency);
            // the balance of a non-cash account is closed for roles without money.view_bank_balances (TZ 03 §4.1)
            if (! $account->type->isCash() && ! $ctx->can('money.view_bank_balances')) {
                throw InsufficientFunds::because('insufficient_funds', "На счёте «{$account->name}» не хватает денег", ['account_id' => $accountId]);
            }
            throw InsufficientFunds::because('insufficient_funds', "В «{$account->name}» {$have->format()}, не хватает {$missing->format()}", [
                'account_id' => $accountId, 'balance' => (int) $balance->balance, 'requested' => -$amount,
            ]);
        }

        $entry = new MoneyEntry;
        $entry->forceFill($ctx->entryDefaults() + $attributes + [
            'account_id' => $accountId,
            'currency' => $account->currency->value,
            'amount' => $amount,
            'exchange_rate' => $rate->toString(),
            'amount_kzt' => $kzt,
            'article_id' => $this->articleId($ctx, $article),
            'reversal_of_entry_id' => $reversalOfEntryId,
        ])->save();

        $balance->forceFill([
            'balance' => $newBalance,
            'balance_kzt_hist' => (int) $balance->balance_kzt_hist + $kzt,
            'last_entry_id' => $entry->id,
            'updated_at' => $ctx->now,
        ])->save();

        return $entry;
    }

    /** Inverse entry (same account, article, rate; opposite amounts). */
    public function reverse(PostingContext $ctx, MoneyEntry $original): MoneyEntry
    {
        return $this->record(
            $ctx,
            (int) $original->account_id,
            -(int) $original->amount,
            (int) $original->article_id,
            array_filter([
                'counterparty_type' => $original->counterparty_type,
                'counterparty_id' => $original->counterparty_id,
                'sale_payment_id' => $original->sale_payment_id,
                'expense_payment_id' => $original->expense_payment_id,
                'comment' => $original->comment,
                'exchange_rate' => (string) $original->exchange_rate,
            ], fn ($v) => $v !== null),
            -(int) $original->amount_kzt,
            $original->id,
        );
    }

    public function account(int $accountId): MoneyAccount
    {
        return $this->accounts[CurrentOrganization::instance()->requireId().':'.$accountId] ??= MoneyAccount::query()->findOrFail($accountId);
    }

    private function articleId(PostingContext $ctx, ArticleCode|string|int $article): int
    {
        if (is_int($article)) {
            return $article;
        }
        $code = $article instanceof ArticleCode ? $article->value : $article;

        return $this->articles[$ctx->organization->id.':'.$code] ??= MoneyArticle::byCode($code)->id;
    }
}
