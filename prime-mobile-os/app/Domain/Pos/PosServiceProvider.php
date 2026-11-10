<?php

namespace App\Domain\Pos;

use App\Domain\Ledger\Services\Shifts;
use App\Domain\Pos\Contracts\NullTradeInProvider;
use App\Domain\Pos\Contracts\TradeInProvider;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\ShellHook;
use App\Support\Ui\WorkingStore;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\ServiceProvider;

/** Cash register module: trade-in point (empty until the module binds it) and the shift line in the store plate. */
class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(TradeInProvider::class, NullTradeInProvider::class);
    }

    public function boot(): void
    {
        FilamentView::registerRenderHook(ShellHook::SIDEBAR_STORE_STATUS, function (): string {
            $user = auth()->user();
            if (! $user || ! CurrentOrganization::instance()->id()) {
                return '';
            }
            $store = WorkingStore::get($user);
            if (! $store) {
                return '';
            }
            $shift = app(Shifts::class)->current($store);

            return e($shift
                ? __('Смена с :time', ['time' => $shift->opened_at->setTimezone(CurrentOrganization::instance()->require()->timezone)->format('H:i')])
                : __('Смена закрыта'));
        });
    }
}
