<?php

namespace App\Support\Routing;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

/**
 * Route groups for modules (one system, two addresses — TZ 05 §1). A module keeps its routes in
 * routes/modules/<slug>.php; the file is required by routes/web.php (ModuleRoutes::load()).
 *
 *   ModuleRoutes::erp(function () {            // erp.<domain>, logged in, firm context, device/lock checks
 *       Route::get('/unit/{code}', UnitPassport::class)->name('unit');   // route('erp.unit', …)
 *   });
 *   ModuleRoutes::erpGuest(fn () => …);         // erp.<domain>, no login (invitation links, webhooks)
 *   ModuleRoutes::storefront(fn () => …);       // <domain> — the public site, name prefix «storefront.»
 *
 * Filament pages/resources do NOT need routes here: they are discovered from app/Filament/<Module>/….
 */
final class ModuleRoutes
{
    public static function load(): void
    {
        foreach (glob(base_path('routes/modules/*.php')) ?: [] as $file) {
            require $file;
        }
    }

    public static function erp(Closure $routes, string $name = 'erp.'): void
    {
        $panel = Filament::getPanel('admin');

        Route::domain(self::erpDomain())
            ->middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()])
            ->name($name)
            ->group($routes);
    }

    public static function erpGuest(Closure $routes): void
    {
        Route::domain(self::erpDomain())
            ->middleware(Filament::getPanel('admin')->getMiddleware())
            ->name('erp.')
            ->group($routes);
    }

    public static function storefront(Closure $routes): void
    {
        Route::domain(self::shopDomain())
            ->middleware('web')
            ->name('storefront.')
            ->group($routes);
    }

    public static function erpDomain(): ?string
    {
        return config('prime.erp_domain') ?: null;
    }

    public static function shopDomain(): ?string
    {
        return config('prime.shop_domain') ?: null;
    }
}
