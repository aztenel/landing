<?php

namespace App\Domain\Ledger\Services;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recomputes the SHA-256 hash chain of a journal for the current organization (TZ 02 §2.5 p.5, §13.2 check 12).
 * Returns null when intact, otherwise the first broken chain_seq. Read-only; used by the nightly reconciliation.
 */
class ChainVerifier
{
    public const JOURNALS = ['inventory_movements', 'money_entries', 'debt_entries', 'bonus_entries'];

    public function firstBrokenLink(string $journal): ?int
    {
        if (! in_array($journal, self::JOURNALS, true)) {
            throw new InvalidArgumentException("Unknown journal {$journal}");
        }
        $orgId = CurrentOrganization::instance()->requireId();
        $previous = '';
        $expectedSeq = 1;
        $rows = DB::table($journal, 't')
            // same payload as the trigger pm_journal_chain(): NULL columns are left out, so a later NULLable column
            // added to the journal does not change the payload of existing rows
            ->selectRaw("t.chain_seq, t.hash, jsonb_strip_nulls(to_jsonb(t) - 'hash')::text AS payload")
            ->where('t.organization_id', $orgId)
            ->orderBy('t.chain_seq')
            ->cursor();

        foreach ($rows as $row) {
            if ((int) $row->chain_seq !== $expectedSeq || hash('sha256', $previous.$row->payload) !== $row->hash) {
                return $expectedSeq;
            }
            $previous = $row->hash;
            $expectedSeq++;
        }

        return null;
    }
}
