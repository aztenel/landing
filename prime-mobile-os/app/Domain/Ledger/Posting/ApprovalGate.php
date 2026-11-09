<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Access\AccessDecision;

/**
 * Bridge to the Approvals module (TZ 03 §6). The core asks «is there an approved request for this document that
 * covers this decision?». The default binding (NoApprovals) answers «no», so anything above a limit throws
 * ApprovalRequired. The Approvals module binds its own implementation (checks documents.approval_request_id,
 * the check fingerprint, expiry, single use) in its service provider.
 */
interface ApprovalGate
{
    public function isApproved(Document $document, string $approvalType, AccessDecision $decision): bool;
}
