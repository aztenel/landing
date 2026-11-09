<?php

namespace App\Http\Middleware\Shell;

use App\Domain\Shell\Services\AuditLog;
use App\Domain\Shell\Services\DeviceRegistry;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every ERP request (and every Livewire update — persistent middleware) is bound to a device of the firm
 * (TZ 03 §8). A device switched off by the owner ends the session on its next request: the cash register
 * polls every 10 s, so it closes within 10 s (TZ 03 §8.3 p.2).
 */
class EnsureDeviceAllowed
{
    public const DISABLED_MESSAGE = 'Это устройство отключено владельцем. Обратитесь к владельцу';

    public function __construct(private readonly DeviceRegistry $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! CurrentOrganization::instance()->has()) {
            return $next($request);
        }

        $device = $this->devices->touch($request, $user);
        if ($device->isDisabled()) {
            AuditLog::record('auth', 'login_refused', $device, ['why' => __(self::DISABLED_MESSAGE)], $user);
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('shell.login_error', __(self::DISABLED_MESSAGE));

            return self::bounce($request, Filament::getLoginUrl() ?? '/login');
        }

        return $next($request);
    }

    /** Livewire/XHR gets 401 (the browser reloads the page and sees the login), a page gets a redirect. */
    public static function bounce(Request $request, string $to): Response
    {
        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) {
            return response()->json(['message' => __('Сессия закрыта'), 'redirect' => $to], 401);
        }

        return redirect()->to($to);
    }
}
