<?php

use App\Http\Controllers\Stock\ExportController;
use App\Livewire\Stock\UnitPassport;
use App\Support\Routing\ModuleRoutes;
use Illuminate\Support\Facades\Route;

/*
 | Module «stock»: lists are Filament pages (app/Filament/Stock/Pages). The passport of a phone has its own address
 | /unit/{PM-код | IMEI} — route erp.stock.units.show (TZ 04 §1); other modules link to it by this name.
 */
ModuleRoutes::erp(function () {
    Route::get('/unit/{code}', UnitPassport::class)->where('code', '[A-Za-z0-9\-]{1,40}')->name('stock.units.show');
    Route::get('/stock/export', ExportController::class)->name('stock.export');
});
