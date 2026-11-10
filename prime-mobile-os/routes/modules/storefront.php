<?php

use App\Http\Controllers\Storefront\SiteController;
use App\Http\Controllers\Storefront\WhatsAppController;
use App\Http\Storefront\StorefrontContext;
use App\Support\Routing\ModuleRoutes;
use Illuminate\Support\Facades\Route;

// Public site (TZ 05 §9.1): ru without a prefix, kk under /kk. Names: storefront.<page> and storefront.kk.<page>.
ModuleRoutes::storefront(function () {
    Route::middleware(StorefrontContext::class)->group(function () {
        foreach (['ru' => '', 'kk' => 'kk'] as $lang => $prefix) {
            Route::prefix($prefix)->name($lang === 'kk' ? 'kk.' : '')->group(function () {
                Route::get('/', [SiteController::class, 'home'])->name('home');
                Route::get('/catalog/{category?}/{brand?}', [SiteController::class, 'catalog'])->name('catalog');
                Route::get('/bu', [SiteController::class, 'used'])->name('used');
                Route::get('/model/{slug}', [SiteController::class, 'model'])->name('model');
                Route::get('/phone/{code}', [SiteController::class, 'phone'])->name('phone');
                Route::get('/item/{code}', [SiteController::class, 'item'])->name('item');
                Route::get('/search', [SiteController::class, 'search'])->name('search');
                Route::get('/store', [SiteController::class, 'store'])->name('store');
                Route::get('/trade-in', [SiteController::class, 'tradein'])->name('tradein');
                Route::get('/{slug}', [SiteController::class, 'page'])->whereIn('slug', ['rassrochka', 'garantiya', 'privacy', 'about'])->name('page');
                Route::get('/go/wa', WhatsAppController::class)->middleware('throttle:30,1')->name('wa');
            });
        }
        Route::get('/sitemap.xml', [SiteController::class, 'sitemap'])->name('sitemap');
        Route::get('/robots.txt', [SiteController::class, 'robots'])->name('robots');
        Route::get('/preview', [SiteController::class, 'preview'])->name('preview');
        Route::get('/{code}', [SiteController::class, 'short'])->where('code', '(?i)pm-\d+')->name('short');
    });
});

// the ERP address is never indexed
ModuleRoutes::erpGuest(function () {
    Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain']))->name('robots');
});
