<?php

namespace App\Domain\Shell\Services;

use App\Domain\Shell\Enums\DeviceType;
use App\Domain\Shell\Models\Device;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Screen lock after idle (TZ 04 Э1.3, TZ 03 §9). The session stays alive (cart, drafts), the page is closed
 * until the password is entered again. The browser counts idle time and asks the server to lock; the server
 * checks it too (time of the last activity in the session), so a stopped script does not keep a screen open.
 *
 * Timeouts (minutes, settings «Безопасность»): store computer 10, iPhone 5, laptop of admin/accountant/owner 15.
 * Full logout: laptops after 12 h, iPhone of the owner after 7 days (TZ 03 §9).
 */
class ScreenLock
{
    public const LOCKED = 'shell.locked_at';

    public const ACTIVE = 'shell.active_at';

    public const LOGIN_AT = 'shell.login_at';

    public const FAILS = 'shell.unlock_fails';

    public function timeoutMinutes(User $user, ?Device $device, Request $request): int
    {
        $settings = CurrentOrganization::instance()->get()?->settings();
        $get = fn (string $key, int $default) => max(1, (int) ($settings?->get('security.'.$key) ?? $default));

        if (DeviceRegistry::isPhone((string) $request->userAgent())) {
            return $get('lock_iphone', (int) config('prime.lock.iphone', 5));
        }
        if ($device?->type === DeviceType::StoreComputer || ! Access::sees(['org.settings', 'report.dashboard', 'money.accounts'], $user)) {
            return $get('lock_store_computer', (int) config('prime.lock.store_computer', 10));
        }

        return $get('lock_laptop', (int) config('prime.lock.laptop', 15));
    }

    public function maxSessionHours(User $user, Request $request): int
    {
        return DeviceRegistry::isPhone((string) $request->userAgent()) && Access::isOwner($user) ? 24 * 7 : 12;
    }

    public function isLocked(Session $session): bool
    {
        return (bool) $session->get(self::LOCKED);
    }

    public function lock(Session $session, User $user, string $why = 'idle'): void
    {
        if (! $this->isLocked($session)) {
            $session->put(self::LOCKED, now()->getTimestamp());
            AuditLog::record('auth', 'lock', null, ['why' => $why], $user);
        }
    }

    /** @return bool true = unlocked; false = wrong PIN / password (after 5 → the caller logs out). PIN — of this device (TZ 03 §7.1). */
    public function unlock(Session $session, User $user, string $password): bool
    {
        $device = app(DeviceRegistry::class)->current(request());
        $byPin = app(Pins::class)->check($user, $device, $password);
        if (! $byPin && ! Hash::check($password, $user->getAuthPassword())) {
            $session->put(self::FAILS, (int) $session->get(self::FAILS, 0) + 1);

            return false;
        }
        $session->forget([self::LOCKED, self::FAILS]);
        $session->put(self::ACTIVE, now()->getTimestamp());
        AuditLog::record('auth', 'unlock', null, [], $user);

        return true;
    }

    public function failures(Session $session): int
    {
        return (int) $session->get(self::FAILS, 0);
    }

    public function touch(Session $session): void
    {
        $session->put(self::ACTIVE, now()->getTimestamp());
    }

    /** Idle longer than the timeout (+30 s for the browser warning) → lock. */
    public function isIdleTooLong(Session $session, int $minutes): bool
    {
        $active = (int) $session->get(self::ACTIVE, 0);

        return $active > 0 && now()->getTimestamp() - $active > $minutes * 60 + (int) config('prime.lock.warning_seconds', 30);
    }

    public function isSessionTooOld(Session $session, int $hours): bool
    {
        $login = (int) $session->get(self::LOGIN_AT, 0);

        return $login > 0 && now()->getTimestamp() - $login > $hours * 3600;
    }
}
