<?php

namespace App\Domain\Ledger\Exceptions;

/** Not enough money on an account that cannot go negative. */
class InsufficientFunds extends PostingException
{
    public string $reason = 'insufficient_funds';
}
