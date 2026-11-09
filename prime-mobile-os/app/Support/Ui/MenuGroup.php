<?php

namespace App\Support\Ui;

/**
 * Groups of the ERP menu in their order (TZ 04 §1): Работа — Учёт — Управление.
 * A page/resource says where it lives with the trait App\Filament\Shell\Concerns\HasErpAccess:
 *   protected static ?MenuGroup $menuGroup = MenuGroup::Work;  + $navigationSort inside the group.
 */
enum MenuGroup: string
{
    case Work = 'work';
    case Accounting = 'accounting';
    case Management = 'management';

    public function label(): string
    {
        return match ($this) {
            self::Work => __('Работа'),
            self::Accounting => __('Учёт'),
            self::Management => __('Управление'),
        };
    }

    /** @return list<string> labels in menu order (for Panel::navigationGroups()) */
    public static function labels(): array
    {
        return array_map(fn (self $g) => $g->label(), self::cases());
    }
}
