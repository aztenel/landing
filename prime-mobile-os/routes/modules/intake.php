<?php

use App\Http\Controllers\Intake\TemplateController;
use App\Support\Routing\ModuleRoutes;
use Illuminate\Support\Facades\Route;

/* Module «intake»: pages /intake and /intake/new are Filament pages (app/Filament/Intake/Pages). */
ModuleRoutes::erp(function () {
    Route::get('/intake/template.xlsx', TemplateController::class)->name('intake.template');
});
