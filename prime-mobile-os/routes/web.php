<?php

use App\Support\Routing\ModuleRoutes;

// One system, two addresses (TZ 05 §1): the ERP answers on config('prime.erp_domain') (Filament panel with an
// empty path), the storefront on config('prime.shop_domain') (routes/modules/storefront.php).
// Module routes: routes/modules/<slug>.php.
ModuleRoutes::load();
