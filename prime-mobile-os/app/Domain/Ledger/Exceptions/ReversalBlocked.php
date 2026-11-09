<?php

namespace App\Domain\Ledger\Exceptions;

/** Reversal is not possible (later documents rely on this one, already reversed, …). */
class ReversalBlocked extends PostingException
{
    public string $reason = 'reversal_blocked';
}
