<?php

use App\Http\Controllers\Printing\PrintController;
use App\Http\Controllers\Printing\PublicReceiptController;
use App\Http\Storefront\StorefrontContext;
use App\Support\Routing\ModuleRoutes;
use Illuminate\Support\Facades\Route;

// Print forms Ф1–Ф12 (TZ 06 §6–§9), names erp.print.*; Ф5 (акт trade-in) — erp.tradein.print.
ModuleRoutes::erp(function () {
    Route::prefix('print')->name('print.')->controller(PrintController::class)->group(function () {
        Route::get('/sale/{document}', 'sale')->whereNumber('document')->name('sale');                 // Ф1
        Route::get('/return/{document}', 'returnReceipt')->whereNumber('document')->name('return');      // Ф2
        Route::get('/warranty/{document}', 'warranty')->whereNumber('document')->name('warranty');      // Ф3
        Route::get('/invoice/{document}', 'invoice')->whereNumber('document')->name('invoice');         // Ф4
        Route::get('/price-tags', 'priceTags')->name('price_tags');                                      // Ф6
        Route::get('/labels', 'labels')->name('labels');                                                 // Ф7
        Route::get('/accessory-labels', 'accessoryLabels')->name('accessory_labels');                    // Ф8
        Route::get('/warranty-case/{id}', 'warrantyCase')->whereNumber('id')->name('warranty_case');    // Ф9
        Route::get('/shift/{id}', 'shift')->whereNumber('id')->name('shift');                            // Ф10
        Route::get('/debt/{document}', 'debtReceipt')->whereNumber('document')->name('debt');           // Ф11
        Route::get('/reconciliation', 'reconciliation')->name('reconciliation');                         // Ф12
        Route::get('/test', 'test')->name('test');
    });
});

// Electronic receipt and warranty for the buyer, no login (TZ 06 §6.5, §8): <shop domain>/r/<token>
ModuleRoutes::storefront(function () {
    Route::middleware(StorefrontContext::class)->get('/r/{token}', PublicReceiptController::class)
        ->where('token', '[A-Za-z0-9]{22}')->name('receipt');
});
