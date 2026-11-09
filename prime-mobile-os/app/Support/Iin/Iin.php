<?php

namespace App\Support\Iin;

/**
 * Kazakhstan IIN/BIN: 12 digits with a control digit (two weight passes, mod 11). TZ 03 §17.
 */
final class Iin
{
    public static function normalize(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    public static function isValid(string $raw): bool
    {
        $iin = self::normalize($raw);
        if (! preg_match('/^\d{12}$/', $iin)) {
            return false;
        }
        $digits = array_map('intval', str_split($iin));

        $control = self::weighted($digits, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
        if ($control === 10) {
            $control = self::weighted($digits, [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2]);
        }

        return $control !== 10 && $control === $digits[11];
    }

    /** «•••• •••• 1234» (TZ 03 §4.1). */
    public static function mask(?string $last4): string
    {
        return '•••• •••• '.($last4 ?? '????');
    }

    /** @param int[] $digits @param int[] $weights */
    private static function weighted(array $digits, array $weights): int
    {
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $digits[$i] * $w;
        }

        return $sum % 11;
    }
}
