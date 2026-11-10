<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Support\Tenancy\TenancyServiceProvider::class,
    App\Domain\Ledger\LedgerServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\ShellServiceProvider::class,
    App\Domain\Approvals\ApprovalsServiceProvider::class,
    App\Domain\Pos\PosServiceProvider::class,
];
