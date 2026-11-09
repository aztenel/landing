<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects the organization of the request (TZ 03 §7.3 p.4, §12):
 *  - session('organization_id') if the user still has an active membership there;
 *  - otherwise the only active organization of the user;
 *  - several organizations and none chosen → redirect to the chooser (route organization.select);
 *  - no active membership → 403 «Доступ закрыт».
 * Sets CurrentOrganization (and the spatie permission team) for the rest of the request.
 */
class SetCurrentOrganization
{
    public const SESSION_KEY = 'organization_id';

    public function __construct(private readonly CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $chosen = $request->session()->get(self::SESSION_KEY);
        if ($chosen && ! $user->hasActiveMembershipIn((int) $chosen)) {
            $request->session()->forget(self::SESSION_KEY);
            $chosen = null;
        }

        if (! $chosen) {
            $organizations = $user->activeOrganizations();
            if ($organizations->count() === 1) {
                $chosen = $organizations->first()->id;
                $request->session()->put(self::SESSION_KEY, $chosen);
            } elseif ($organizations->count() > 1) {
                if ($request->routeIs('organization.select', 'organization.switch')) {
                    return $next($request);
                }

                return redirect()->route('organization.select');
            } else {
                abort(403, 'Доступ закрыт. Обратитесь к владельцу');
            }
        }

        $this->current->set((int) $chosen);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $next($request);
    }
}
