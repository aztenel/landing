<?php

namespace App\Domain\Ledger\Exceptions;

use App\Support\Exceptions\UserFacingException;

/** Posting failed for a business reason; the document stays a draft; the message is for the user. */
class PostingException extends UserFacingException
{
    public string $reason = 'posting';
}
