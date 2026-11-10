<?php

namespace App\Domain\Money\Services;

use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\AccountBalance;
use App\Domain\Ledger\Models\Shift;
use App\Domain\Ledger\Posting\TransactionRetry;
use App\Domain\Ledger\Services\Shifts;
use App\Domain\Money\Drafts\CashAdjustmentDraft;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shift with the cash count (TZ 02 §8, 01 П1 / П19, audit №57). The seller enters what is in each cash register of the
 * store (₸ and $); «должно быть» = the balance of the register by the journal at that moment. A difference ≠ 0 —
 * document РКС per register (comment required), over the threshold — the owner is notified (the handler does it).
 *
 *   $shift = $shiftCash->open($store, $seller, [$cashKzt->id => 12_450_000], 'comment if any');
 *   $shiftCash->close($shift, $seller, [$cashKzt->id => 124_450_000, $cashUsd->id => 50_000], 'сдача');
 *
 * Amounts are minor units (tiyn / cents). $denominations (optional): [account_id => [nominal => count]] for the report.
 */
class ShiftCash
{
    public function __construct(private readonly Shifts $shifts) {}

    /** Cash registers of the store, the order of the count form. @return Collection<int, MoneyAccount> */
    public function registers(Store|int $store): Collection
    {
        return MoneyAccount::query()->where('store_id', $store instanceof Store ? $store->id : $store)
            ->where('type', 'cash')->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
    }

    /** @return array<int, int> account_id => balance by the journal */
    public function expected(Store|int $store): array
    {
        $ids = $this->registers($store)->pluck('id')->all();
        $balances = AccountBalance::query()->whereIn('account_id', $ids)->pluck('balance', 'account_id');

        return collect($ids)->mapWithKeys(fn ($id) => [$id => (int) ($balances[$id] ?? 0)])->all();
    }

    public function open(Store|int $store, User $user, array $counted, ?string $comment = null, array $denominations = []): Shift
    {
        return TransactionRetry::run(function () use ($store, $user, $counted, $comment, $denominations) {
            $shift = $this->shifts->open($store, $user);
            $this->count($shift, 'open', $user, $counted, $comment, $denominations);

            return $shift;
        });
    }

    public function close(Shift $shift, User $user, array $counted, ?string $comment = null, array $denominations = []): Shift
    {
        return TransactionRetry::run(function () use ($shift, $user, $counted, $comment, $denominations) {
            // the shift row first: postings in flight finish (or fail «смена не открыта»), then the balances are final
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw PostingException::because('shift_not_open', 'Смена уже закрыта');
            }
            $this->count($locked, 'close', $user, $counted, $comment, $denominations);

            return $this->shifts->close($locked, $user);
        });
    }

    private function count(Shift $shift, string $phase, User $user, array $counted, ?string $comment, array $denominations): void
    {
        $comment = trim((string) $comment);
        $expected = $this->expected((int) $shift->store_id);
        $registers = $this->registers((int) $shift->store_id);
        foreach ($registers as $account) {
            if (! array_key_exists($account->id, $counted) || ! is_int($counted[$account->id]) || $counted[$account->id] < 0) {
                throw PostingException::because('count_required', 'Введите, сколько денег в кассе «'.$account->name.'»', ['account_id' => $account->id]);
            }
        }
        foreach ($registers as $account) {
            $diff = $counted[$account->id] - $expected[$account->id];
            $documentId = null;
            if ($diff !== 0) {
                if ($comment === '') {
                    throw PostingException::because('comment_required', 'Касса не сходится — напишите почему', ['account_id' => $account->id]);
                }
                $documentId = CashAdjustmentDraft::make((int) $shift->store_id, $account, Money::of($diff, $account->currency->value), $comment, $phase)
                    ->post($user)->id;
            }
            DB::table('shift_counts')->insert([
                'organization_id' => $shift->organization_id, 'shift_id' => $shift->id, 'account_id' => $account->id,
                'phase' => $phase, 'expected' => $expected[$account->id], 'counted' => $counted[$account->id], 'diff' => $diff,
                'denominations' => isset($denominations[$account->id]) ? json_encode($denominations[$account->id]) : null,
                'document_id' => $documentId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
