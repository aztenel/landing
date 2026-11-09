<?php

namespace App\Domain\Ledger\Exceptions;

/** The document type has no handler yet (a module must register one). */
class NotImplementedDocumentType extends PostingException
{
    public string $reason = 'not_implemented';
}
