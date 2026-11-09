<?php

namespace App\Http\Middleware\Shell;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First login (TZ 04 Э1.2): a person whose password was set by somebody else (integrator, migration) must
 * set his own password and accept the client-data rules before anything else opens.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->must_change_password
            && ! $request->routeIs('erp.first-login', 'filament.admin.auth.logout')) {
            return EnsureDeviceAllowed::bounce($request, route('erp.first-login'));
        }

        return $next($request);
    }
}
