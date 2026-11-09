<?php

namespace App\Support\Imei;

use InvalidArgumentException;

/**
 * IMEI helpers: normalization, 15 digits + Luhn check digit (ENGINEERING.md rule 6).
 * Serial numbers (iPad without SIM, «DMPXK2LQ7F») are not IMEIs — see isSerial().
 */
final class Imei
{
    /** Strip spaces, dashes, dots, slashes; uppercase letters (serials). */
    public static function normalize(string $raw): string
    {
        return strtoupper(preg_replace('/[\s\-\.\/\x{00A0}]+/u', '', trim($raw)) ?? '');
    }

    public static function isValid(string $raw): bool
    {
        $imei = self::normalize($raw);

        return (bool) preg_match('/^\d{15}$/', $imei) && self::luhnValid($imei);
    }

    /** @throws InvalidArgumentException with a message for the seller */
    public static function assertValid(string $raw): string
    {
        $imei = self::normalize($raw);
        if (! preg_match('/^\d{15}$/', $imei)) {
            throw new InvalidArgumentException("IMEI {$raw}: должно быть 15 цифр");
        }
        if (! self::luhnValid($imei)) {
            throw new InvalidArgumentException("IMEI {$raw}: неверная контрольная цифра (проверка по Луну)");
        }

        return $imei;
    }

    public static function luhnValid(string $digits): bool
    {
        if ($digits === '' || ! ctype_digit($digits)) {
            return false;
        }

        return self::luhnCheckDigit(substr($digits, 0, -1)) === (int) substr($digits, -1);
    }

    /** Check digit for a payload (14 digits for IMEI). */
    public static function luhnCheckDigit(string $payload): int
    {
        if (! ctype_digit($payload)) {
            throw new InvalidArgumentException('Luhn payload must contain digits only.');
        }
        $sum = 0;
        $double = true; // the rightmost payload digit is doubled
        for ($i = strlen($payload) - 1; $i >= 0; $i--) {
            $d = (int) $payload[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = ! $double;
        }

        return (10 - $sum % 10) % 10;
    }

    /** Complete a 14-digit body with its check digit. */
    public static function withCheckDigit(string $body14): string
    {
        $body14 = self::normalize($body14);
        if (! preg_match('/^\d{14}$/', $body14)) {
            throw new InvalidArgumentException('IMEI body must be 14 digits.');
        }

        return $body14.self::luhnCheckDigit($body14);
    }

    /** Serial number: 6–20 latin letters/digits, not a 15-digit IMEI. */
    public static function isSerial(string $raw): bool
    {
        $value = self::normalize($raw);

        return (bool) preg_match('/^[A-Z0-9]{6,20}$/', $value) && ! preg_match('/^\d{15}$/', $value);
    }

    /** Screen format as in the design: «35 693812 4471 206». */
    public static function format(string $imei): string
    {
        $imei = self::normalize($imei);
        if (! preg_match('/^\d{15}$/', $imei)) {
            return $imei;
        }

        return substr($imei, 0, 2).' '.substr($imei, 2, 6).' '.substr($imei, 8, 4).' '.substr($imei, 12, 3);
    }
}
