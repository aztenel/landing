<?php

namespace App\Support\Money;

/**
 * Rounding modes used by the accounting rules (TZ 02 §3.2).
 *  - HalfUp: «половина — вверх», symmetric (away from zero) so that a reversal is the exact mirror;
 *  - Down: toward zero (bonus accrual «до целого тенге вниз»);
 *  - Up: away from zero (mass price recalculation «до шага, вверх»).
 */
enum Rounding
{
    case HalfUp;
    case Down;
    case Up;

    /**
     * Integer division with rounding. $denominator must be positive.
     */
    public function divide(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('Denominator must be positive.');
        }

        $sign = $numerator < 0 ? -1 : 1;
        $abs = abs($numerator);
        $quotient = intdiv($abs, $denominator);
        $remainder = $abs % $denominator;

        if ($remainder !== 0) {
            $quotient += match ($this) {
                self::HalfUp => (2 * $remainder >= $denominator) ? 1 : 0,
                self::Down => 0,
                self::Up => 1,
            };
        }

        return $sign * $quotient;
    }
}
