<?php

namespace App\Filament\Shell;

use App\Domain\Shell\Services\HomeResolver;
use App\Filament\Shell\Pages\Auth\Login;
use App\Http\Middleware\Shell\EnsureDeviceAllowed;
use App\Http\Middleware\Shell\EnsurePasswordChanged;
use App\Http\Middleware\Shell\EnsureScreenUnlocked;
use App\Http\Middleware\Shell\EnsureTwoFactor;
use App\Http\Middleware\Shell\ErpResponseHeaders;
use App\Support\Ui\Accent;
use App\Support\Ui\ErpSearch;
use App\Support\Ui\MenuGroup;
use Filament\Contracts\Plugin;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;

/**
 * The ERP frame (module «shell»): address, look, menu groups, module discovery, security middleware.
 * AdminPanelProvider only adds `->plugin(ShellPlugin::make())`; modules never touch the provider:
 *
 *   app/Filament/<Module>/Resources|Pages|Widgets|Clusters  — discovered automatically;
 *   routes/modules/<slug>.php                               — App\Support\Routing\ModuleRoutes;
 *   render hooks App\Support\Ui\ShellHook                   — topbar / sidebar / mobile places.
 */
class ShellPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'prime-shell';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->path('')
            ->domain(config('prime.erp_domain') ?: null)
            ->login(Login::class)
            ->brandName('Prime Mobile')
            ->favicon('/icons/favicon.svg')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Onest', provider: LocalFontProvider::class)
            ->colors([
                'primary' => Accent::Blue->filamentPalette(),
                'gray' => Color::Zinc,
                'danger' => Color::Red,
                'success' => Color::Green,
                'warning' => Color::Amber,
                'info' => Color::Blue,
            ])
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Light)
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarWidth('236px')
            ->navigationGroups(MenuGroup::labels())
            ->globalSearch(ErpSearch::class)
            ->homeUrl(fn (): string => app(HomeResolver::class)->url())
            ->authMiddleware([
                EnsureDeviceAllowed::class,
                EnsurePasswordChanged::class,
                EnsureTwoFactor::class,
                EnsureScreenUnlocked::class,
                ErpResponseHeaders::class,
            ], isPersistent: true);

        foreach (glob(app_path('Filament/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $module = basename($dir);
            $ns = "App\\Filament\\{$module}";
            if (is_dir("{$dir}/Resources")) {
                $panel->discoverResources(in: "{$dir}/Resources", for: "{$ns}\\Resources");
            }
            if (is_dir("{$dir}/Pages")) {
                $panel->discoverPages(in: "{$dir}/Pages", for: "{$ns}\\Pages");
            }
            if (is_dir("{$dir}/Widgets")) {
                $panel->discoverWidgets(in: "{$dir}/Widgets", for: "{$ns}\\Widgets");
            }
            if (is_dir("{$dir}/Clusters")) {
                $panel->discoverClusters(in: "{$dir}/Clusters", for: "{$ns}\\Clusters");
            }
        }
    }

    public function boot(Panel $panel): void {}
}
