<?php

namespace App\Support\Money;

/**
 * Currencies of the system (TZ 02 §3.1). Customers pay only in KZT (assumption Д6); USD is used for purchases,
 * supplier debts, the USD cash/safe accounts and analytics. Both have 100 minor units.
 */
enum Currency: string
{
    case KZT = 'KZT';
    case USD = 'USD';

    public function symbol(): string
    {
        return match ($this) {
            self::KZT => '₸',
            self::USD => '$',
        };
    }

    public function minorUnits(): int
    {
        return 100;
    }

    public static function from2(Currency|string $value): self
    {
        return $value instanceof self ? $value : self::from(strtoupper($value));
    }
}
