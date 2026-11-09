<?php

namespace App\Support\Money;

use InvalidArgumentException;
use Stringable;

/**
 * Exchange rate «₸ per 1 unit of foreign currency», exact to 4 decimals (DB type numeric(14,4)).
 * Stored as an integer scaled by 10 000 so that conversions never use floats.
 */
final class Rate implements Stringable
{
    public const SCALE = 10000;

    private function __construct(public readonly int $scaled)
    {
        if ($scaled <= 0) {
            throw new InvalidArgumentException('Exchange rate must be positive.');
        }
    }

    /** Accepts "512.40", "512,4", "515" or an int number of tenge. Floats are rejected on purpose. */
    public static function of(string|int|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_int($value)) {
            return new self($value * self::SCALE);
        }

        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid exchange rate: {$value}");
        }
        $fraction = rtrim($m[2] ?? '', '0');
        if (strlen($fraction) > 4) {
            throw new InvalidArgumentException("Exchange rate has more than 4 decimals: {$value}");
        }
        $fraction = str_pad($fraction, 4, '0');

        return new self((int) $m[1] * self::SCALE + (int) $fraction);
    }

    public static function fromScaled(int $scaled): self
    {
        return new self($scaled);
    }

    public static function one(): self
    {
        return new self(self::SCALE);
    }

    public function isOne(): bool
    {
        return $this->scaled === self::SCALE;
    }

    /** "512.4000" — the DB representation. */
    public function toString(): string
    {
        return intdiv($this->scaled, self::SCALE).'.'.str_pad((string) ($this->scaled % self::SCALE), 4, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** "512,40" — for the screen (2 decimals, TZ 02 §3.1). */
    public function format(): string
    {
        $hundredths = Rounding::HalfUp->divide($this->scaled, 100);

        return number_format(intdiv($hundredths, 100), 0, '', "\u{00A0}").','.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    public function equals(self $other): bool
    {
        return $this->scaled === $other->scaled;
    }

    /** Relative change to another rate in basis points of a percent (×100): 500 = 5.00 %. */
    public function changePercentScaled(self $previous): int
    {
        return Rounding::HalfUp->divide(abs($this->scaled - $previous->scaled) * 10000, $previous->scaled);
    }
}
