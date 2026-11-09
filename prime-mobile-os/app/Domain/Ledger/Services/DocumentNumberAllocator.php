<?php

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Models\DocumentCounter;
use App\Support\DocumentNumber\DocumentNumber;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issues numbers without gaps among posted documents: the counter row is locked FOR UPDATE in the posting
 * transaction (LockManager step 7) and incremented right before the document becomes posted (TZ 02 §2.3).
 */
class DocumentNumberAllocator
{
    /** Increment an already locked counter and return the formatted number. */
    public function issue(DocumentCounter $lockedCounter): string
    {
        $next = (int) $lockedCounter->last_number + 1;
        $lockedCounter->forceFill(['last_number' => $next])->save();

        return DocumentNumber::format($lockedCounter->series, (int) $lockedCounter->year, $next);
    }

    /** Standalone allocation for numbered non-documents (e.g. warranty cases «ГРО»). Call inside a transaction. */
    public function allocate(string $series, int $year): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Allocate document numbers inside a transaction.');
        }
        DB::table('document_counters')->insertOrIgnore([
            'organization_id' => CurrentOrganization::instance()->requireId(),
            'series' => $series, 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $counter = DocumentCounter::query()->where('series', $series)->where('year', $year)->lockForUpdate()->firstOrFail();

        return $this->issue($counter);
    }
}
