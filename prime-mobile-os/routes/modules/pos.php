<?php

use App\Http\Controllers\Pos\ReceiptController;
use App\Support\Routing\ModuleRoutes;
use Illuminate\Support\Facades\Route;

/*
 | Module «pos»: the cash register page is a Filament page (/sale, app/Filament/Pos/Pages/Sale.php).
 | Temporary 80 mm receipt until the printing module (Ф1) replaces it — contract in docs/modules/pos.md.
 */
ModuleRoutes::erp(function () {
    Route::get('/sale/receipt/{document}', ReceiptController::class)->whereNumber('document')->name('pos.receipt');
});
