<?php

namespace App\Domain\Shell\Services;

use App\Domain\Shell\Models\Device;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * 2FA by an authenticator app (TZ 03 §7.1–7.5): TOTP of RFC 6238 (30 s, 6 digits, SHA-1 — Google Authenticator,
 * «Пароли» iOS). Mandatory for the owner, the admin and the accountant; the others — by the firm setting. 5 wrong codes
 * → a pause of 15 minutes. 10 one-time recovery codes. «Запомнить этот iPhone на 30 дней» — a personal device only.
 */
final class TwoFactor
{
    public const SESSION = 'shell.2fa_at';

    public const COOKIE = 'pm_2fa';

    public const REQUIRED_ROLES = ['owner', 'admin', 'accountant'];

    public static function required(User $user): bool
    {
        if (! CurrentOrganization::instance()->has()) {
            return false;
        }
        $role = app(AccessChecker::class)->role($user)?->name;

        return in_array($role, self::REQUIRED_ROLES, true) || (bool) CurrentOrganization::instance()->require()->settings()->get('security.two_factor_all', false);
    }

    public static function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    // ---- TOTP ----
    public static function newSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $s = '';
        for ($i = 0; $i < 32; $i++) {
            $s .= $alphabet[random_int(0, 31)];
        }

        return $s;
    }

    private static function base32(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32))) as $c) {
            $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0).pack('N*', $counter), self::base32($secret), true);
        $o = ord($hash[19]) & 0xF;
        $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);

        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** ±1 step (30 s) for a clock that is a little off. */
    public static function verifyCode(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\D/', '', $code);
        $t = $time ?? time();
        foreach ([-30, 0, 30] as $d) {
            if (hash_equals(self::code($secret, $t + $d), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function uri(User $user, string $secret): string
    {
        $issuer = rawurlencode('Prime Mobile');

        return 'otpauth://totp/'.$issuer.':'.rawurlencode((string) $user->phone).'?secret='.$secret.'&issuer='.$issuer.'&digits=6&period=30';
    }

    // ---- setup ----
    /** Step 1: a new secret kept in the session until the first right code. */
    public function begin(User $user): string
    {
        $secret = self::newSecret();
        session(['shell.2fa_setup' => Crypt::encryptString($secret)]);

        return $secret;
    }

    /** Step 2: the code from the app confirms the secret; returns 10 recovery codes (shown once). @return list<string> */
    public function confirm(User $user, string $code): array
    {
        $enc = session('shell.2fa_setup');
        $secret = $enc ? Crypt::decryptString($enc) : null;
        if (! $secret || ! self::verifyCode($secret, $code)) {
            throw UserFacingException::because('2fa_code', __('Код не подходит. Проверьте время на телефоне'));
        }
        $codes = collect(range(1, 10))->map(fn () => Str::upper(Str::random(4).'-'.Str::random(4)))->all();
        $user->forceFill(['two_factor_secret' => Crypt::encryptString($secret), 'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $codes))])->save();
        session()->forget('shell.2fa_setup');
        session([self::SESSION => now()->toIso8601String()]);
        app(AuditLog::class)->record('auth', '2fa.enabled', $user);

        return $codes;
    }

    /** Owner «Сбросить 2FA» of an employee who lost his phone (§7.5 п. 2). */
    public function reset(User $user, User $by): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        DB::table('two_factor_trusts')->where('user_id', $user->id)->delete();
        app(AuditLog::class)->record('auth', '2fa.reset', $user, ['by' => $by->id]);
    }

    // ---- challenge ----
    public function verify(User $user, string $code, Request $request, bool $remember, ?Device $device): void
    {
        $key = '2fa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw UserFacingException::because('2fa_locked', __('Код не подходит 5 раз. Попробуйте через :m мин.', ['m' => (int) ceil(RateLimiter::availableIn($key) / 60)]));
        }
        $ok = self::verifyCode(Crypt::decryptString((string) $user->two_factor_secret), $code) || $this->useRecovery($user, $code);
        if (! $ok) {
            RateLimiter::hit($key, 15 * 60);
            app(AuditLog::class)->record('auth', '2fa.failed', $user);
            throw UserFacingException::because('2fa_code', __('Код не подходит. Проверьте время на телефоне'));
        }
        RateLimiter::clear($key);
        session([self::SESSION => now()->toIso8601String()]);
        if ($remember && $device && $device->type?->value !== 'store_computer') {
            $token = Str::random(48);
            DB::table('two_factor_trusts')->insert(['user_id' => $user->id, 'device_id' => $device->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30), 'created_at' => now()]);
            cookie()->queue(cookie(self::COOKIE, $user->id.'|'.$token, 30 * 24 * 60, null, null, $request->isSecure(), true, false, 'lax'));
        }
        app(AuditLog::class)->record('auth', '2fa.passed', $user);
    }

    private function useRecovery(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));
        if (! preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) {
            return false;
        }
        $list = (array) json_decode((string) $user->two_factor_recovery_codes, true);
        foreach ($list as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($list[$i]);
                $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($list))])->save();
                app(AuditLog::class)->record('auth', '2fa.recovery_used', $user, ['left' => count($list)]);

                return true;
            }
        }

        return false;
    }

    /** The session already passed the code, or this device is remembered for 30 days. */
    public function passed(User $user, Request $request): bool
    {
        if (session(self::SESSION)) {
            return true;
        }
        [$uid, $token] = array_pad(explode('|', (string) $request->cookie(self::COOKIE), 2), 2, null);
        if ((int) $uid === $user->id && $token && DB::table('two_factor_trusts')->where('user_id', $user->id)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->exists()) {
            session([self::SESSION => now()->toIso8601String()]);

            return true;
        }

        return false;
    }
}
