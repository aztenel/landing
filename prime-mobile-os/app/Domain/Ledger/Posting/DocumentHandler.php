<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;

/**
 * One handler per document type. Registered in HandlerRegistry (core types in LedgerServiceProvider, module types
 * in the module's service provider). The Poster calls, inside ONE transaction, after locking the document row:
 *
 *   validate()  → structural checks of the draft (no writes, no locks yet)
 *   lockPlan()  → which rows to lock; LockManager locks them in the global order
 *   post()      → re-check under locks, authorize/limits via $ctx->authorize(), write journals ONLY through
 *                 InventoryLedger / MoneyLedger / DebtLedger / BonusLedger, update the document's own detail rows
 *
 * Reversal is generic (Reverser writes inverse entries for every journal row of the document);
 * a handler only adds assertReversible() (rule 2.1 p.4) and afterReverse() (detail-table side effects).
 */
interface DocumentHandler
{
    public function type(): DocumentType;

    /** Does posting need the USD rate (USD amounts anywhere in the document)? */
    public function requiresExchangeRate(Document $document): bool;

    public function validate(Document $document, PostingContext $ctx): void;

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan;

    public function post(Document $document, PostingContext $ctx): void;

    /** Throw ReversalBlocked if later posted documents rely on this one (e.g. a return of this sale). */
    public function assertReversible(Document $document, PostingContext $ctx): void;

    /** Extra rows to lock for the reversal besides those derived from the journal entries. */
    public function reversalLockPlan(Document $document): LockPlan;

    /** Detail-table side effects after the inverse entries were written (e.g. sale_lines.is_active = false). */
    public function afterReverse(Document $original, PostingContext $ctx): void;
}
