<?php

namespace App\Http\Storefront;

use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;

/**
 * Public site context (TZ 05 §1): the firm by the domain of the request (settings.storefront.domain; one firm today —
 * the first active), the language by the /kk prefix. A switched-off site (§4.2) shows «Скоро» to buyers; staff open
 * it in preview from the ERP («Открыть сайт» sets a signed cookie for 8 hours, §14).
 */
final class StorefrontContext
{
    public function handle(Request $request, Closure $next)
    {
        $host = $request->getHost();
        $org = Organization::query()->where('is_active', true)->get()
            ->first(fn ($o) => ($o->settings['storefront']['domain'] ?? null) === $host)
            ?? Organization::query()->where('is_active', true)->orderBy('id')->first();
        abort_unless($org, 404);
        CurrentOrganization::instance()->set($org);
        $preview = hash_equals(self::previewToken($org->id), (string) $request->cookie('pm_preview'));
        Site::boot($org, $request->segment(1) === 'kk' ? 'kk' : 'ru', $preview);
        app()->setLocale(Site::$lang);

        if (! Site::settings()->enabled() && ! $preview && ! $request->routeIs('storefront.robots', 'storefront.preview')) {
            return response()->view('storefront.soon', [], 200)->header('X-Robots-Tag', 'noindex');
        }
        $response = $next($request);
        if ($preview && ! Site::settings()->enabled()) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    public static function previewToken(int $orgId): string
    {
        return hash_hmac('sha256', 'site-preview:'.$orgId.':'.now()->format('Y-m-d'), (string) config('app.key'));
    }
}
