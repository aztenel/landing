<?php

namespace App\Domain\Ledger\Events;

use App\Domain\Ledger\Enums\DocumentType;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after a reversal commits (push to the owner about every reversal, TZ 03 §11.4). */
final class DocumentReversed
{
    use Dispatchable;

    public function __construct(
        public readonly int $originalDocumentId,
        public readonly int $reversalDocumentId,
        public readonly int $organizationId,
        public readonly DocumentType $originalType,
    ) {}
}
