<?php

namespace App\Domain\Approvals;

use App\Domain\Approvals\Services\DatabaseApprovalGate;
use App\Domain\Ledger\Posting\ApprovalGate;
use App\Domain\Notifications\Channels\NullPushSender;
use App\Domain\Notifications\Channels\PushSender;
use App\Support\Ui\ShellHook;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Module «approvals» (TZ 03 §6, TZ 04 Э28): the database ApprovalGate replaces the core NoApprovals, the bell
 * goes into the top bar of the ERP frame, the minute tick (expiry, escalation to the admin, repeated push) is
 * scheduled in routes/console.php.
 */
class ApprovalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseApprovalGate::class);
        $this->app->singleton(ApprovalGate::class, fn ($app) => $app->make(DatabaseApprovalGate::class));
        $this->app->singletonIf(PushSender::class, NullPushSender::class);
    }

    public function boot(): void
    {
        FilamentView::registerRenderHook(ShellHook::TOPBAR_BELL, fn (): string => Blade::render('@livewire(\App\Livewire\Approvals\Bell::class)'));
    }
}
