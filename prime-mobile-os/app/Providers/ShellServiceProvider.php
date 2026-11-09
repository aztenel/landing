<?php

namespace App\Providers;

use App\Domain\Shell\Services\AuditLog;
use App\Domain\Shell\Services\DeviceRegistry;
use App\Domain\Tenancy\Models\Membership;
use App\Filament\Shell\ShellLoginResponse;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Throwable;

/**
 * Module «shell» (ERP frame + UI kit), see docs/UI-KIT.md:
 *  - Blade components <x-ui::…> from resources/views/shell/components;
 *  - translations lang/modules/<slug>/<locale>.json of every module;
 *  - head/body pieces of every ERP page (accent, «старый ноутбук», theme, PWA, JS of the shell);
 *  - action journal fields (IP, device, store, role) for every activity.
 */
class ShellServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Access::class);
        $this->app->scoped(DeviceRegistry::class);
        $this->app->bind(LoginResponse::class, ShellLoginResponse::class);
    }

    public function boot(): void
    {
        Blade::anonymousComponentPath(resource_path('views/shell/components'), 'ui');

        foreach (glob(lang_path('modules/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $this->app['translator']->addJsonPath($dir);
        }

        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_START, fn (): string => view('shell.layout.head-start')->render());
        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_END, fn (): string => view('shell.layout.head-end')->render());

        $this->journalFields();

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User && CurrentOrganization::instance()->has()) {
                AuditLog::record('auth', 'logout', null, [], $event->user);
            }
        });
    }

    /** TZ 03 §11.2: IP, browser, device, store and the role at that moment on every journal row. */
    private function journalFields(): void
    {
        $activity = ActivitylogServiceProvider::determineActivityModel();
        $activity::creating(function (Model $entry): void {
            try {
                $request = request();
                if (! app()->runningInConsole() || app()->runningUnitTests()) {
                    $entry->setAttribute('ip_address', $entry->getAttribute('ip_address') ?? $request->ip());
                    $entry->setAttribute('user_agent', $entry->getAttribute('user_agent') ?? Str::limit((string) $request->userAgent(), 250, ''));
                }
                if (! CurrentOrganization::instance()->has()) {
                    return;
                }
                if ($entry->getAttribute('device_id') === null) {
                    $entry->setAttribute('device_id', app(DeviceRegistry::class)->current()?->id);
                }
                $causer = $entry->getAttribute('causer_type') === (new User)->getMorphClass() ? User::query()->find($entry->getAttribute('causer_id')) : null;
                if ($causer && $entry->getAttribute('role') === null) {
                    $causer->unsetRelation('roles');
                    $entry->setAttribute('role', $causer->roles()->first()?->title);
                    $stores = Membership::query()->where('user_id', $causer->id)->value('store_ids');
                    $stores = is_string($stores) ? json_decode($stores, true) : $stores;
                    if (is_array($stores) && count($stores) === 1 && $entry->getAttribute('store_id') === null) {
                        $entry->setAttribute('store_id', (int) $stores[0]);
                    }
                }
            } catch (Throwable) {
                // The journal row is written even if the extra fields cannot be resolved.
            }
        });
    }
}
