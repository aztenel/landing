<?php

namespace App\Domain\Ledger\Events;

use App\Domain\Ledger\Enums\DocumentType;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched AFTER the posting transaction commits. Listeners (storefront refresh, push to the owner, printing,
 * fiscal receipt) must set CurrentOrganization::run($organizationId, …) themselves when queued.
 */
final class DocumentPosted
{
    use Dispatchable;

    public function __construct(
        public readonly int $documentId,
        public readonly int $organizationId,
        public readonly DocumentType $type,
    ) {}
}
