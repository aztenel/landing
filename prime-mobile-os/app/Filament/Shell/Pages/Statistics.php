<?php

namespace App\Filament\Shell\Pages;

use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Ui\MenuGroup;
use Filament\Pages\Dashboard;

/**
 * Э2 «Статистика» at the root «/» (TZ 04 §1). The frame of erp-1-statistika.png; the figures come from the reports
 * module (it registers its widgets here). Until then the page shows the frame with an honest empty state.
 */
class Statistics extends Dashboard
{
    use HasErpAccess;

    protected static string|array|null $permission = ['report.dashboard', 'report.own'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'shell.pages.statistics';

    public static function getNavigationLabel(): string
    {
        return __('Статистика');
    }

    public function getTitle(): string
    {
        return __('Статистика');
    }

    public function getWidgets(): array
    {
        return [];
    }
}
