<?php

use App\Http\Controllers\Reports\ExportController;
use App\Support\Routing\ModuleRoutes;
use Illuminate\Support\Facades\Route;

// Reports (TZ 06 §5): Excel of any report with the same filters and the same closed columns as on the screen.
ModuleRoutes::erp(function () {
    Route::get('/reports/export', ExportController::class)->name('reports.export');
});
