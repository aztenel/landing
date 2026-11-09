<?php

namespace App\Support\Phone;

use InvalidArgumentException;

/**
 * Kazakhstan phone numbers in E.164: +77010000001 (TZ 03 §7.2). Accepts «8 701 000 00 01», «+7 (701) 000-00-01».
 */
final class Phone
{
    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            $digits = '7'.substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7'.$digits;
        }
        if (! preg_match('/^7\d{10}$/', $digits)) {
            throw new InvalidArgumentException("Неверный номер телефона: {$raw}");
        }

        return '+'.$digits;
    }

    /** «+7 701 000 00 01» */
    public static function format(string $e164): string
    {
        $d = ltrim($e164, '+');
        if (strlen($d) !== 11) {
            return $e164;
        }

        return '+'.$d[0].' '.substr($d, 1, 3).' '.substr($d, 4, 3).' '.substr($d, 7, 2).' '.substr($d, 9, 2);
    }

    /** «+7 7•• ••• 45 67» for integrators and notifications (TZ 03 §4.1). */
    public static function mask(string $e164): string
    {
        $d = ltrim($e164, '+');

        return '+'.$d[0].' '.$d[1].'•• ••• '.substr($d, 7, 2).' '.substr($d, 9, 2);
    }
}
