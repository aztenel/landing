<?php

namespace App\Domain\Ledger\Exceptions;

/** Not enough stock (accessory quantity, unit not available). */
class InsufficientStock extends PostingException
{
    public string $reason = 'insufficient_stock';
}
