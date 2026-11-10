<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Models\Document;

/** Sensible defaults for handlers: no USD, nothing extra for reversal. */
abstract class AbstractDocumentHandler implements DocumentHandler
{
    public function requiresExchangeRate(Document $document): bool
    {
        return false;
    }

    public function assertReversible(Document $document, PostingContext $ctx): void {}

    public function reversalLockPlan(Document $document): LockPlan
    {
        return new LockPlan;
    }

    public function afterReverse(Document $original, PostingContext $ctx): void {}

    public function shiftRequirement(Document $document): ShiftRequirement
    {
        return ShiftRequirement::IfStoreCash;
    }
}
