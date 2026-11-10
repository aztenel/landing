<?php

namespace App\Http\Middleware\Shell;

use App\Domain\Shell\Services\TwoFactor;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TZ 03 §7.3 п. 3: after the password — the code from the app (owner, admin, accountant always; others if they turned it
 * on or the firm asks everyone). Without a set-up 2FA a required role goes to the setup first.
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! config('prime.two_factor') || ! $user instanceof User || $request->routeIs('erp.two-factor*', 'erp.first-login*', 'filament.admin.auth.logout')) {
            return $next($request);
        }
        $tf = app(TwoFactor::class);
        if (TwoFactor::enabled($user)) {
            if (! $tf->passed($user, $request)) {
                $request->hasHeader('X-Livewire') || session()->put('url.intended', $request->fullUrl());

                return EnsureDeviceAllowed::bounce($request, route('erp.two-factor'));
            }
        } elseif (TwoFactor::required($user)) {
            return EnsureDeviceAllowed::bounce($request, route('erp.two-factor.setup'));
        }

        return $next($request);
    }
}
