<?php

use App\Http\Controllers\TradeIn\ActPrintController;
use App\Support\Routing\ModuleRoutes;
use Illuminate\Support\Facades\Route;

/* Module «tradein»: pages /tradein, /tradein/new, /tradein/grid, /stock/prep (app/Filament/TradeIn/Pages). */
ModuleRoutes::erp(function () {
    Route::get('/tradein/act/{document}', ActPrintController::class)->whereNumber('document')->name('tradein.print');
});
