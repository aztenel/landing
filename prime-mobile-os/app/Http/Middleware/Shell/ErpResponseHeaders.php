<?php

namespace App\Http\Middleware\Shell;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP pages are never stored by the browser or a proxy (TZ 03 §8.3, §18 p.7: «no-store для страниц ERP»),
 * and the interface speaks the language of the person (users.locale: ru | kk).
 */
class ErpResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && in_array($user->locale, ['ru', 'kk'], true)) {
            app()->setLocale($user->locale);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
