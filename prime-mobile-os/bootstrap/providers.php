<?php

use App\Domain\Approvals\ApprovalsServiceProvider;
use App\Domain\Ledger\LedgerServiceProvider;
use App\Domain\Pos\PosServiceProvider;
use App\Domain\Storefront\StorefrontServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ShellServiceProvider;
use App\Support\Tenancy\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    LedgerServiceProvider::class,
    AdminPanelProvider::class,
    ShellServiceProvider::class,
    ApprovalsServiceProvider::class,
    PosServiceProvider::class,
    StorefrontServiceProvider::class,
];
