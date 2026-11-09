<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\NotImplementedDocumentType;
use App\Domain\Ledger\Models\Document;

/**
 * Placeholder for document types whose module is not built yet (transfer, write-off, trade-in, …).
 * Posting such a document fails with a clear message; the module registers its handler to replace this one.
 */
final class NotImplementedHandler extends AbstractDocumentHandler
{
    public function __construct(private readonly DocumentType $documentType) {}

    public function type(): DocumentType
    {
        return $this->documentType;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        throw NotImplementedDocumentType::because('not_implemented', "Проведение документа «{$this->documentType->label()}» ещё не реализовано", [
            'type' => $this->documentType->value,
        ]);
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        return new LockPlan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $this->validate($document, $ctx);
    }
}
