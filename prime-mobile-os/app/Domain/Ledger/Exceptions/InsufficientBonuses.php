<?php

namespace App\Domain\Ledger\Exceptions;

/** Not enough available bonuses. */
class InsufficientBonuses extends PostingException
{
    public string $reason = 'insufficient_bonuses';
}
