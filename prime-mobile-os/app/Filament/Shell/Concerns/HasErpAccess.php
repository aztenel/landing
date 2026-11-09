<?php

namespace App\Filament\Shell\Concerns;

use App\Support\Ui\Access;
use App\Support\Ui\Hotkey;
use App\Support\Ui\MenuGroup;

/**
 * «Видно и доступно, если есть право X» for Filament pages and resources (TZ 03 §18 p.1, TZ 04 §0.4 п. 9).
 *
 *   class TeamPage extends Page {
 *       use HasErpAccess;
 *       protected static string|array|null $permission = ['staff.manage', 'role.manage'];   // any of
 *       protected static ?MenuGroup $menuGroup = MenuGroup::Management;
 *       protected static ?string $navigationIcon = 'shield';      // Lucide name (App\Support\Ui\Icon)
 *       protected static ?int $navigationSort = 30;
 *       protected static ?string $slug = 'team';                   // address /team (TZ 04 §1)
 *   }
 *
 * - canAccess() (pages) and canViewAny() (resources) use Access::sees(): a direct URL without the right → 403.
 * - The menu item is hidden without the right (Filament asks canAccess()).
 * - Actions must still check on the server: Access::authorize('…') inside the action.
 * - Mobile tab bar / «Ещё» show the page if it is in the menu.
 */
trait HasErpAccess
{
    /** @return string|list<string>|null null = any active member of the firm */
    public static function requiredPermission(): string|array|null
    {
        return property_exists(static::class, 'permission') ? static::$permission : null;
    }

    public static function canAccess(): bool
    {
        return Access::sees(static::requiredPermission());
    }

    public static function canViewAny(): bool
    {
        return Access::sees(static::requiredPermission());
    }

    public static function getNavigationGroup(): ?string
    {
        $group = property_exists(static::class, 'menuGroup') ? static::$menuGroup : null;

        return $group instanceof MenuGroup ? $group->label() : parent::getNavigationGroup();
    }

    /**
     * Page hotkeys (TZ 04 §0.6), shown in the «?» cheat sheet. Override in the page.
     *
     * @return list<Hotkey>
     */
    public function hotkeys(): array
    {
        return [];
    }
}
