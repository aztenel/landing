<?php

namespace App\Domain\Ledger\Posting\Ledgers;

use App\Domain\Ledger\Enums\BonusKind;
use App\Domain\Ledger\Exceptions\InsufficientBonuses;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Models\BonusAllocation;
use App\Domain\Ledger\Models\BonusEntry;
use App\Domain\Ledger\Models\CustomerBonusBalance;
use App\Domain\Ledger\Posting\PostingContext;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The ONLY writer of bonus_entries, bonus_allocations and customer_bonus_balances (TZ 02 §7). Whole bonuses,
 * 1 bonus = 1 ₸.
 *
 * Model: positive «source» entries (accrual, opening, manual +) can be consumed; consuming entries (spend, cancel,
 * expire, manual −) are allocated to sources FIFO by expiry (bonus_allocations). Returning spent bonuses writes a
 * non-source + entry with NEGATIVE allocations to the very same sources — the customer gets «the same» bonuses
 * with their original expiry. remaining(source) = amount − Σ allocations.
 * available = Σ remaining of sources with available_from ≤ now < expires_at; pending = available_from > now.
 */
class BonusLedger
{
    public function accrue(
        PostingContext $ctx,
        int $customerId,
        int $bonuses,
        CarbonInterface $availableFrom,
        ?CarbonInterface $expiresAt,
        BonusKind $kind = BonusKind::Accrual,
        ?int $saleLineId = null,
        ?string $comment = null,
    ): BonusEntry {
        if ($bonuses <= 0) {
            throw new LogicException('Accrual must be positive.');
        }
        $ctx->locks->bonus($customerId);
        $entry = $this->insert($ctx, [
            'customer_id' => $customerId, 'amount' => $bonuses, 'kind' => $kind->value, 'is_source' => true,
            'available_from' => $availableFrom, 'expires_at' => $expiresAt, 'sale_line_id' => $saleLineId, 'comment' => $comment,
        ]);
        $this->refresh($ctx, $customerId, $entry->id);

        return $entry;
    }

    /** Spend AVAILABLE bonuses FIFO by expiry (payment with bonuses). */
    public function spend(PostingContext $ctx, int $customerId, int $bonuses, BonusKind $kind = BonusKind::Spend, ?int $saleLineId = null): BonusEntry
    {
        $result = $this->consume($ctx, $customerId, $bonuses, $kind, [], includePending: false, allowShortfall: false, saleLineId: $saleLineId);

        return $result['entry'];
    }

    /**
     * Generic consumption. Preferred sources first (e.g. the accrual of a returned line, even if pending),
     * then other sources FIFO by expiry (available ones; pending too if $includePending).
     *
     * @param  list<int>  $preferredSourceIds
     * @return array{entry: BonusEntry|null, shortfall: int}
     */
    public function consume(
        PostingContext $ctx,
        int $customerId,
        int $bonuses,
        BonusKind $kind,
        array $preferredSourceIds = [],
        bool $includePending = true,
        bool $allowShortfall = false,
        ?int $saleLineId = null,
        ?int $reversalOf = null,
    ): array {
        if ($bonuses <= 0) {
            throw new LogicException('Consumption must be positive.');
        }
        $ctx->locks->bonus($customerId);
        $now = $ctx->now;
        // preferred sources (the accrual being cancelled / reversed) are taken even if pending or already expired:
        // cancelling an expired accrual must not eat the customer's other, still valid bonuses
        $sources = $this->sources($customerId)
            ->filter(fn ($s) => in_array($s->id, $preferredSourceIds, true) || $s->expires_at === null || $s->expires_at > $now)
            ->filter(fn ($s) => in_array($s->id, $preferredSourceIds, true) || $includePending || $s->available_from <= $now)
            ->sortBy(fn ($s) => [in_array($s->id, $preferredSourceIds, true) ? 0 : 1, $s->expires_at?->timestamp ?? PHP_INT_MAX, $s->id])
            ->values();

        $plan = [];
        $left = $bonuses;
        foreach ($sources as $source) {
            if ($left === 0) {
                break;
            }
            $take = min($left, $source->remaining);
            $plan[$source->id] = $take;
            $left -= $take;
        }

        if ($left > 0 && ! $allowShortfall) {
            $available = $bonuses - $left;
            throw InsufficientBonuses::because('insufficient_bonuses', "Недостаточно бонусов: доступно {$available}, нужно {$bonuses}", [
                'available' => $available, 'requested' => $bonuses,
            ]);
        }
        $consumed = $bonuses - $left;
        if ($consumed === 0) {
            return ['entry' => null, 'shortfall' => $left];
        }

        $entry = $this->insert($ctx, [
            'customer_id' => $customerId, 'amount' => -$consumed, 'kind' => $kind->value, 'is_source' => false,
            'sale_line_id' => $saleLineId, 'reversal_of_entry_id' => $reversalOf,
        ]);
        foreach ($plan as $sourceId => $take) {
            $this->allocate($ctx, $entry->id, $sourceId, $take, $entry->id);
        }
        $this->refresh($ctx, $customerId, $entry->id);

        return ['entry' => $entry, 'shortfall' => $left];
    }

    /**
     * Give back bonuses that a consuming entry took (return of a line paid with bonuses): negative allocations to
     * the same sources, the latest-expiring first. Cannot exceed what is still allocated.
     */
    public function restore(PostingContext $ctx, BonusEntry $consumer, int $bonuses, BonusKind $kind = BonusKind::ReturnOfSpent, ?int $reversalOf = null): BonusEntry
    {
        if ($bonuses <= 0 || $consumer->amount >= 0) {
            throw new LogicException('Restore needs a consuming entry and a positive amount.');
        }
        $customerId = (int) $consumer->customer_id;
        $ctx->locks->bonus($customerId);
        $net = $this->netAllocations($consumer->id);
        if (array_sum(array_column($net, 'amount')) < $bonuses) {
            throw new LogicException('Cannot restore more bonuses than were consumed.');
        }
        usort($net, fn ($a, $b) => [$b['expires'] ?? PHP_INT_MAX, $b['source']] <=> [$a['expires'] ?? PHP_INT_MAX, $a['source']]);

        $entry = $this->insert($ctx, [
            'customer_id' => $customerId, 'amount' => $bonuses, 'kind' => $kind->value, 'is_source' => false,
            'sale_line_id' => $consumer->sale_line_id, 'reversal_of_entry_id' => $reversalOf,
        ]);
        $left = $bonuses;
        foreach ($net as $row) {
            if ($left === 0) {
                break;
            }
            $give = min($left, $row['amount']);
            if ($give > 0) {
                $this->allocate($ctx, $consumer->id, $row['source'], -$give, $entry->id);
                $left -= $give;
            }
        }
        $this->refresh($ctx, $customerId, $entry->id);

        return $entry;
    }

    /** Inverse of an entry of the original document (generic reversal). */
    public function reverse(PostingContext $ctx, BonusEntry $original): BonusEntry
    {
        $customerId = (int) $original->customer_id;
        if ($original->is_source) {
            $remaining = $this->remaining($original->id, (int) $original->amount);
            if ($remaining < (int) $original->amount) {
                throw ReversalBlocked::because('bonuses_spent', "Бонусы по документу уже потрачены ({$original->amount} начислено, осталось {$remaining})");
            }

            return $this->consume($ctx, $customerId, (int) $original->amount, BonusKind::Reversal, [$original->id], includePending: true, allowShortfall: false, reversalOf: $original->id)['entry'];
        }
        if ((int) $original->amount < 0) {
            $net = array_sum(array_column($this->netAllocations($original->id), 'amount'));
            if ($net <= 0) {
                throw ReversalBlocked::because('bonuses_restored', 'Бонусы по документу уже возвращены');
            }

            return $this->restore($ctx, $original, $net, BonusKind::Reversal, $original->id);
        }

        // a restore entry (return_of_spent): take the same bonuses again from the same sources
        $rows = BonusAllocation::query()->where('entry_id', $original->id)->where('amount', '<', 0)->get();
        $ctx->locks->bonus($customerId);
        foreach ($rows as $row) {
            $source = BonusEntry::query()->findOrFail($row->accrual_entry_id);
            if ($this->remaining($source->id, (int) $source->amount) < -$row->amount) {
                throw ReversalBlocked::because('bonuses_spent', 'Возвращённые бонусы уже потрачены — сторно невозможно');
            }
        }
        $entry = $this->insert($ctx, [
            'customer_id' => $customerId, 'amount' => -(int) $original->amount, 'kind' => BonusKind::Reversal->value, 'is_source' => false,
            'sale_line_id' => $original->sale_line_id, 'reversal_of_entry_id' => $original->id,
        ]);
        foreach ($rows as $row) {
            $this->allocate($ctx, (int) $row->spend_entry_id, (int) $row->accrual_entry_id, -(int) $row->amount, $entry->id);
        }
        $this->refresh($ctx, $customerId, $entry->id);

        return $entry;
    }

    /**
     * Recompute the copy from the journal (also used by the Loyalty activation/expiry job, which must lock the row).
     */
    public function refresh(PostingContext|CarbonInterface $ctxOrNow, int $customerId, ?int $lastEntryId = null): CustomerBonusBalance
    {
        $now = $ctxOrNow instanceof PostingContext ? $ctxOrNow->now : $ctxOrNow;
        $balance = $ctxOrNow instanceof PostingContext
            ? $ctxOrNow->locks->bonus($customerId)
            : CustomerBonusBalance::query()->where('customer_id', $customerId)->lockForUpdate()->firstOrFail();

        $available = 0;
        $pending = 0;
        $nextExpire = null;
        foreach ($this->sources($customerId) as $s) {
            if ($s->expires_at !== null && $s->expires_at <= $now) {
                continue;
            }
            if ($s->available_from !== null && $s->available_from > $now) {
                $pending += $s->remaining;
            } else {
                $available += $s->remaining;
            }
            if ($s->expires_at !== null && ($nextExpire === null || $s->expires_at < $nextExpire)) {
                $nextExpire = $s->expires_at;
            }
        }
        $balance->forceFill([
            'available' => $available,
            'pending' => $pending,
            'next_expire_at' => $nextExpire,
            'last_entry_id' => $lastEntryId ?? $balance->last_entry_id,
            'updated_at' => $now,
        ])->save();

        return $balance;
    }

    /** Sources with remaining > 0. @return Collection<int, object{id:int, remaining:int, available_from:?\Carbon\CarbonImmutable, expires_at:?\Carbon\CarbonImmutable}> */
    public function sources(int $customerId): Collection
    {
        return collect(DB::select(
            'SELECT e.id, e.amount - COALESCE(SUM(a.amount), 0) AS remaining, e.available_from, e.expires_at
               FROM bonus_entries e
               LEFT JOIN bonus_allocations a ON a.accrual_entry_id = e.id
              WHERE e.organization_id = ? AND e.customer_id = ? AND e.is_source
              GROUP BY e.id
             HAVING e.amount - COALESCE(SUM(a.amount), 0) > 0
              ORDER BY e.expires_at NULLS LAST, e.id',
            [CurrentOrganization::instance()->requireId(), $customerId], // raw SQL: no global scope, filter the firm
        ))->map(fn ($r) => (object) [
            'id' => (int) $r->id,
            'remaining' => (int) $r->remaining,
            'available_from' => $r->available_from ? CarbonImmutable::parse($r->available_from) : null,
            'expires_at' => $r->expires_at ? CarbonImmutable::parse($r->expires_at) : null,
        ]);
    }

    private function remaining(int $sourceId, int $amount): int
    {
        return $amount - (int) BonusAllocation::query()->where('accrual_entry_id', $sourceId)->sum('amount');
    }

    /** @return list<array{source: int, amount: int, expires: int|null}> net positive allocations of a consumer */
    private function netAllocations(int $consumerId): array
    {
        return array_values(array_filter(array_map(fn ($r) => [
            'source' => (int) $r->accrual_entry_id,
            'amount' => (int) $r->net,
            'expires' => $r->expires_at ? strtotime($r->expires_at) : null,
        ], DB::select(
            'SELECT a.accrual_entry_id, SUM(a.amount) AS net, s.expires_at
               FROM bonus_allocations a JOIN bonus_entries s ON s.id = a.accrual_entry_id
              WHERE a.organization_id = ? AND a.spend_entry_id = ?
              GROUP BY a.accrual_entry_id, s.expires_at',
            [CurrentOrganization::instance()->requireId(), $consumerId],
        )), fn ($r) => $r['amount'] > 0));
    }

    private function allocate(PostingContext $ctx, int $consumerId, int $sourceId, int $amount, int $writerEntryId): void
    {
        $allocation = new BonusAllocation;
        $allocation->forceFill([
            'organization_id' => $ctx->organization->id,
            'spend_entry_id' => $consumerId,
            'accrual_entry_id' => $sourceId,
            'entry_id' => $writerEntryId,
            'amount' => $amount,
            'created_at' => $ctx->now,
        ])->save();
    }

    private function insert(PostingContext $ctx, array $attributes): BonusEntry
    {
        $entry = new BonusEntry;
        $entry->forceFill($ctx->entryDefaults() + $attributes)->save();

        return $entry;
    }
}
