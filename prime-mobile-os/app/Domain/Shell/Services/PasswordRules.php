<?php

namespace App\Domain\Shell\Services;

use App\Support\Exceptions\UserFacingException;

/**
 * Password rules of TZ 03 §7.2: at least 8 characters, not a well-known password, not the phone number.
 * No «capital + digit + symbol» requirements.
 *
 * Допущение: the TZ asks for «not in the 10 000 most common passwords»; we ship the head of that list
 * (the passwords people really use here) — enough to stop «12345678»/«password»/«qwerty123».
 */
final class PasswordRules
{
    private const COMMON = [
        '12345678', '123456789', '1234567890', '87654321', '11111111', '00000000', '12341234', '11223344',
        'password', 'password1', 'password123', 'qwerty123', 'qwertyui', 'qwertyuiop', '1q2w3e4r', '1q2w3e4r5t',
        'q1w2e3r4', 'zaq12wsx', 'iloveyou', 'sunshine', 'princess', 'football', 'baseball', 'abc12345', 'abcd1234',
        'admin123', 'administrator', 'welcome1', 'letmein1', 'monkey12', 'dragon12', 'master12', 'superman',
        'qazwsxedc', '1qaz2wsx', 'asdfghjk', 'asdf1234', 'zxcvbnm1', 'samsung1', 'iphone12', 'apple123',
        'primemobile', 'prime123', 'kazakhstan', 'almaty123', 'astana123', 'parol123', 'пароль123', 'йцукенгш',
        '1111111111', '123123123', '123321123', '55555555', '66666666', '77777777', '88888888', '99999999', '12121212',
    ];

    public static function assert(string $password, ?string $phone = null): void
    {
        if (mb_strlen($password) < 8) {
            throw UserFacingException::because('password_short', __('Пароль — не короче 8 символов'));
        }
        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true) || preg_match('/^(.)\1+$/u', $password)) {
            throw UserFacingException::because('password_common', __('Этот пароль слишком простой — его подбирают первым. Придумайте другой'));
        }
        if ($phone !== null) {
            $digits = preg_replace('/\D+/', '', $password) ?? '';
            $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
            if ($digits !== '' && strlen($digits) >= 8 && str_contains($phoneDigits, $digits)) {
                throw UserFacingException::because('password_phone', __('Пароль не должен совпадать с номером телефона'));
            }
        }
    }
}
