<?php

namespace App\Domain\Shell\Services;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Protection from password guessing (TZ 04 Э1.1, TZ 03 §7.4): 5 wrong passwords in a row for one phone →
 * the phone is paused for 10 minutes; 20 errors from one IP per hour → the IP is paused for an hour.
 * A successful login clears the phone counter.
 */
class LoginGuard
{
    public function phoneKey(string $phone): string
    {
        return 'login:phone:'.sha1($phone);
    }

    public function ipKey(string $ip): string
    {
        return 'login:ip:'.sha1($ip);
    }

    public function isLocked(string $phone, string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->phoneKey($phone), $this->maxAttempts())
            || RateLimiter::tooManyAttempts($this->ipKey($ip), (int) config('prime.login.ip_max_attempts', 20));
    }

    /** @return bool true when this failure locked the phone */
    public function failed(string $phone, string $ip): bool
    {
        RateLimiter::hit($this->phoneKey($phone), $this->lockoutMinutes() * 60);
        RateLimiter::hit($this->ipKey($ip), 3600);

        return RateLimiter::tooManyAttempts($this->phoneKey($phone), $this->maxAttempts());
    }

    public function succeeded(string $phone): void
    {
        RateLimiter::clear($this->phoneKey($phone));
    }

    public function maxAttempts(): int
    {
        return (int) config('prime.login.max_attempts', 5);
    }

    public function lockoutMinutes(): int
    {
        return (int) config('prime.login.lockout_minutes', 10);
    }
}
