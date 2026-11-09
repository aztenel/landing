<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Access\AccessDecision;

/** Default ApprovalGate until the Approvals module exists: nothing is pre-approved. */
final class NoApprovals implements ApprovalGate
{
    public function isApproved(Document $document, string $approvalType, AccessDecision $decision): bool
    {
        return false;
    }
}
