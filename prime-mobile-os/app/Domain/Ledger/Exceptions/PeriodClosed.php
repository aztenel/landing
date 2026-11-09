<?php

namespace App\Domain\Ledger\Exceptions;

/** The business date is inside a closed accounting period. */
class PeriodClosed extends PostingException
{
    public string $reason = 'period_closed';
}
