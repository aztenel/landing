<?php

namespace App\Domain\Ledger\Models;

use LogicException;

/**
 * Journal rows are only inserted (TZ 02 §2.5). The DB trigger is the real guard; this fails earlier in PHP.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' is append-only: corrections are made by reversal.'));
        static::deleting(fn () => throw new LogicException(static::class.' is append-only: corrections are made by reversal.'));
    }
}
