<?php

use App\Http\Controllers\Tenancy\OrganizationSwitchController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['web', 'auth', 'organization'])->group(function () {
    Route::get('/organization/select', [OrganizationSwitchController::class, 'select'])->name('organization.select');
    Route::post('/organization/switch/{organization}', [OrganizationSwitchController::class, 'switch'])->whereNumber('organization')->name('organization.switch');
});
