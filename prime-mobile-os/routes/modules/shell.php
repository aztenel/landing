<?php

use App\Http\Controllers\Shell\FirstLoginController;
use App\Http\Controllers\Shell\HealthController;
use App\Http\Controllers\Shell\InvitationController;
use App\Http\Controllers\Shell\ScreenLockController;
use App\Http\Controllers\Shell\TwoFactorController;
use App\Http\Controllers\Tenancy\OrganizationSwitchController;
use App\Support\Routing\ModuleRoutes;
use Illuminate\Support\Facades\Route;

/*
 | Module «shell»: screen lock (Э1.3), first login (Э1.2), invitation links (TZ 03 §14), firm chooser (Э1).
 | Pages of the shell (/, /team, /settings, /audit, /more) are Filament pages in app/Filament/Shell/Pages.
 */
ModuleRoutes::erp(function () {
    Route::post('/shell/lock', [ScreenLockController::class, 'lock'])->name('shell.lock');
    Route::post('/shell/unlock', [ScreenLockController::class, 'unlock'])->name('shell.unlock');
    Route::post('/shell/activity', [ScreenLockController::class, 'activity'])->name('shell.activity');
    Route::get('/first-login', [FirstLoginController::class, 'show'])->name('first-login');
    Route::post('/first-login', [FirstLoginController::class, 'store'])->name('first-login.store');
    Route::get('/two-factor', [TwoFactorController::class, 'show'])->name('two-factor');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:20,1')->name('two-factor.verify');
    Route::get('/two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/two-factor/setup', [TwoFactorController::class, 'confirm'])->middleware('throttle:20,1')->name('two-factor.confirm');
});

// The firm chooser keeps the route names used by SetCurrentOrganization (core).
ModuleRoutes::erp(function () {
    Route::get('/organization/select', [OrganizationSwitchController::class, 'select'])->name('organization.select');
    Route::post('/organization/switch/{organization}', [OrganizationSwitchController::class, 'switch'])->whereNumber('organization')->name('organization.switch');
}, name: '');

ModuleRoutes::erpGuest(function () {
    Route::get('/health', HealthController::class)->name('health');
    Route::get('/invite/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{20,80}')->name('invitation');
    Route::post('/invite/{token}', [InvitationController::class, 'store'])->where('token', '[A-Za-z0-9]{20,80}')->middleware('throttle:10,1')->name('invitation.store');
});
