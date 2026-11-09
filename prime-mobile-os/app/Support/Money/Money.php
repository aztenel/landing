<?php

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable money amount in MINOR units (tiyn for KZT, cents for USD), int64. No floats anywhere (TZ 02 §3).
 *
 *   Money::kzt(489000)                → 489 000 ₸ (48 900 000 tiyn)
 *   Money::usd('950')->convert(KZT, Rate::of('515'))  → 489 250 ₸ (half-up to 1 tiyn)
 *   Money::kzt(13990)->percent('0.95', Rounding::HalfUp, Money::TENGE)  → 133 ₸
 *   Money::kzt(3000)->allocate([549000_00, 9000_00], Money::TENGE)      → [2952 ₸, 48 ₸]
 */
final class Money implements JsonSerializable, Stringable
{
    /** Step of 1 whole tenge / dollar in minor units. */
    public const TENGE = 100;

    public const NBSP = "\u{00A0}";

    public const MINUS = "\u{2212}";

    private function __construct(public readonly int $amount, public readonly Currency $currency) {}

    public static function of(int $minor, Currency|string $currency): self
    {
        return new self($minor, Currency::from2($currency));
    }

    /** Whole (or decimal-string) tenge: Money::kzt(489000), Money::kzt('2433.33'). */
    public static function kzt(int|string $tenge): self
    {
        return new self(self::parseMajor($tenge), Currency::KZT);
    }

    public static function kztMinor(int $tiyn): self
    {
        return new self($tiyn, Currency::KZT);
    }

    /** Dollars: Money::usd(950), Money::usd('1.20'). */
    public static function usd(int|string $dollars): self
    {
        return new self(self::parseMajor($dollars), Currency::USD);
    }

    public static function usdMinor(int $cents): self
    {
        return new self($cents, Currency::USD);
    }

    public static function zero(Currency|string $currency = Currency::KZT): self
    {
        return new self(0, Currency::from2($currency));
    }

    private static function parseMajor(int|string $major): int
    {
        if (is_int($major)) {
            return $major * 100;
        }
        $value = str_replace([' ', self::NBSP, ','], ['', '', '.'], trim($major));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid money amount: {$major}");
        }
        $minor = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$minor : $minor;
    }

    // ---------------------------------------------------------------- arithmetic

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->amount), $this->currency);
    }

    /** @param  array<int, self>  $items */
    public static function sum(array $items, Currency|string $currency = Currency::KZT): self
    {
        $total = self::zero($currency);
        foreach ($items as $item) {
            $total = $total->plus($item);
        }

        return $total;
    }

    /**
     * Convert to another currency by rate «₸ per 1 unit of foreign currency».
     * USD→KZT: tiyn = cents × rate; KZT→USD: cents = tiyn ÷ rate. Rounded to 1 minor unit (default half-up).
     */
    public function convert(Currency|string $to, Rate $rate, Rounding $rounding = Rounding::HalfUp): self
    {
        $to = Currency::from2($to);
        if ($to === $this->currency) {
            return $this;
        }

        if ($this->currency === Currency::USD && $to === Currency::KZT) {
            return new self($rounding->divide($this->amount * $rate->scaled, Rate::SCALE), $to);
        }

        return new self($rounding->divide($this->amount * Rate::SCALE, $rate->scaled), $to);
    }

    /**
     * Percentage with 2 decimals ("12.00", "0.95", 3), rounded to $step minor units.
     * Bank commission: percent('12', HalfUp, Money::TENGE); bonus: percent('3', Down, Money::TENGE).
     */
    public function percent(string|int $percent, Rounding $rounding = Rounding::HalfUp, int $step = 1): self
    {
        $scaled = self::percentScaled($percent);

        return new self($rounding->divide($this->amount * $scaled, 10000 * $step) * $step, $this->currency);
    }

    /** "12.50" → 1250 (percent ×100). */
    public static function percentScaled(string|int $percent): int
    {
        if (is_int($percent)) {
            return $percent * 100;
        }
        $value = str_replace([',', ' '], ['.', ''], trim($percent));
        if (! preg_match('/^(\d+)(?:\.(\d{1,2})0*)?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid percent: {$percent}");
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** Round to a step in minor units (Money::TENGE = whole tenge). */
    public function roundTo(int $step, Rounding $rounding = Rounding::HalfUp): self
    {
        return new self($rounding->divide($this->amount, $step) * $step, $this->currency);
    }

    /**
     * Split the amount proportionally to integer weights in units of $step using the largest remainder method
     * (TZ 02 §3.2): integer parts first, the missing units go one by one to the largest fractional parts;
     * ties go to the earlier item. Σ result = this amount exactly.
     *
     * @param  array<int|string, int>  $weights  non-negative
     * @return array<int|string, self> same keys as $weights
     */
    public function allocate(array $weights, int $step = 1): array
    {
        if ($this->amount % $step !== 0) {
            throw new InvalidArgumentException("Amount {$this->amount} is not a multiple of step {$step}.");
        }
        $totalWeight = array_sum($weights);
        $units = intdiv(abs($this->amount), $step);
        $sign = $this->amount < 0 ? -1 : 1;

        if ($totalWeight <= 0) {
            if ($units !== 0) {
                throw new InvalidArgumentException('Cannot allocate a non-zero amount over zero weights.');
            }

            return array_map(fn () => new self(0, $this->currency), $weights);
        }

        $base = [];
        $remainders = [];
        $order = 0;
        foreach ($weights as $key => $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('Weights must be non-negative.');
            }
            $product = $units * $weight;
            $base[$key] = intdiv($product, $totalWeight);
            $remainders[] = ['key' => $key, 'rem' => $product % $totalWeight, 'order' => $order++];
        }

        $left = $units - array_sum($base);
        usort($remainders, fn ($a, $b) => [$b['rem'], $a['order']] <=> [$a['rem'], $b['order']]);
        for ($i = 0; $i < $left; $i++) {
            $base[$remainders[$i]['key']]++;
        }

        return array_map(fn (int $u) => new self($sign * $u * $step, $this->currency), $base);
    }

    // ---------------------------------------------------------------- comparison

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /** Whole tenge / dollars, no tiyn (retail prices, discounts, payments must be whole — TZ 02 §3.2). */
    public function isWhole(): bool
    {
        return $this->amount % 100 === 0;
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount <=> $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency->value} vs {$other->currency->value}");
        }
    }

    // ---------------------------------------------------------------- output

    /** Whole major units (half-up), e.g. 489000 for 489 000,00 ₸. */
    public function toMajorRounded(): int
    {
        return Rounding::HalfUp->divide($this->amount, 100);
    }

    /** "489000.00" (decimal string, e.g. for Excel). */
    public function toDecimalString(): string
    {
        $abs = abs($this->amount);

        return ($this->amount < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Screen format (TZ 02 §3.1, design): KZT — whole tenge «489 000 ₸»; USD — «$1 140» or «$1 140.50».
     * Thousands separator and the space before ₸ are NO-BREAK SPACE (U+00A0); minus is U+2212.
     */
    public function format(): string
    {
        $sign = $this->amount < 0 ? self::MINUS : '';

        if ($this->currency === Currency::KZT) {
            return $sign.self::group(abs($this->toMajorRounded())).self::NBSP.'₸';
        }

        $abs = abs($this->amount);
        $cents = $abs % 100;

        return $sign.'$'.self::group(intdiv($abs, 100)).($cents ? '.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT) : '');
    }

    private static function group(int $value): string
    {
        return number_format($value, 0, '', self::NBSP);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency->value];
    }
}
