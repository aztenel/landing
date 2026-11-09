<?php

use App\Support\Routing\ModuleRoutes;
use Illuminate\Support\Facades\Route;

// One system, two addresses (TZ 05 §1): the ERP answers on config('prime.erp_domain') (Filament panel with an
// empty path), the storefront on config('prime.shop_domain'). Module routes: routes/modules/<slug>.php.
ModuleRoutes::storefront(function () {
    Route::get('/', fn () => view('welcome'))->name('home');
});

ModuleRoutes::load();
