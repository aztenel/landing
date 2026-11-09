<?php

namespace App\Domain\Ledger\Exceptions;

/** Only drafts can be posted / edited. */
class DocumentNotDraft extends PostingException
{
    public string $reason = 'not_draft';
}
