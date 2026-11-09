<?php

namespace App\Http\Middleware\Shell;

use App\Domain\Shell\Services\AuditLog;
use App\Domain\Shell\Services\DeviceRegistry;
use App\Domain\Shell\Services\ScreenLock;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Screen lock and full logout by time (TZ 04 Э1.3, TZ 03 §9). While the session is locked no ERP data is
 * served: a page request gets the lock screen (HTTP 423), a Livewire update gets 423 and the browser shows
 * the lock overlay. Livewire requests do not count as activity (polling must not keep the screen open).
 */
class EnsureScreenUnlocked
{
    private const EXEMPT = ['erp.shell.unlock', 'erp.shell.lock', 'filament.admin.auth.logout'];

    public function __construct(private readonly ScreenLock $lock, private readonly DeviceRegistry $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! CurrentOrganization::instance()->has() || $request->routeIs(self::EXEMPT)) {
            return $next($request);
        }
        $session = $request->session();

        if ($this->lock->isSessionTooOld($session, $this->lock->maxSessionHours($user, $request))) {
            AuditLog::record('auth', 'logout', null, ['why' => 'session_age'], $user);
            Filament::auth()->logout();
            $session->invalidate();
            $session->regenerateToken();

            return EnsureDeviceAllowed::bounce($request, Filament::getLoginUrl() ?? '/login');
        }

        $minutes = $this->lock->timeoutMinutes($user, $this->devices->current($request), $request);
        if (! $this->lock->isLocked($session) && $this->lock->isIdleTooLong($session, $minutes)) {
            $this->lock->lock($session, $user, 'idle');
        }

        if ($this->lock->isLocked($session)) {
            if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
                return response()->json(['message' => __('Экран заблокирован'), 'locked' => true], 423);
            }

            return response()->view('shell.auth.locked', ['user' => $user], 423);
        }

        $isLivewire = $request->hasHeader('X-Livewire');
        if (! $isLivewire && ($request->isMethod('GET') || $request->routeIs('erp.shell.activity'))) {
            $this->lock->touch($session);
        }
        if (! $session->has(ScreenLock::LOGIN_AT)) {
            $session->put(ScreenLock::LOGIN_AT, now()->getTimestamp());
        }

        return $next($request);
    }
}
