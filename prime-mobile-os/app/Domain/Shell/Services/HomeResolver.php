<?php

namespace App\Domain\Shell\Services;

use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

/**
 * Where a person lands after login (TZ 04 §1 «После входа»): seller and senior seller → Продажа (/sale);
 * admin → Продажа (Допущение 04); owner and accountant → Статистика (/).
 * If the preferred page is not installed yet or not allowed, the first allowed menu item is used,
 * and the last resort is the start page «/» (it adapts to the role).
 */
class HomeResolver
{
    /** role name => preferred page slugs in order ('' = «/»). Custom roles use 'default'. */
    public const PREFERRED = [
        'owner' => [''],
        'accountant' => [''],
        'admin' => ['sale', ''],
        'senior_seller' => ['sale'],
        'seller' => ['sale'],
        'default' => ['sale', ''],
    ];

    public function url(?User $user = null): string
    {
        $user ??= Filament::auth()->user();
        $panel = Filament::getPanel('admin');
        if (! $user instanceof User || ! CurrentOrganization::instance()->has()) {
            return $panel->getUrl() ?? '/';
        }
        $user->unsetRelation('roles');
        /** @var Role|null $role */
        $role = $user->roles()->first();
        $preferred = self::PREFERRED[$role?->name ?? 'default'] ?? ($role?->is_owner ? [''] : self::PREFERRED['default']);

        foreach ($preferred as $slug) {
            if ($url = $this->pageUrl($slug)) {
                return $url;
            }
        }
        foreach ($panel->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                if ($item->isVisible() && ($url = $item->getUrl())) {
                    return $url;
                }
            }
        }

        return $this->pageUrl('') ?? '/';
    }

    /** URL of an accessible page/resource with that slug, or null. */
    public function pageUrl(string $slug): ?string
    {
        $panel = Filament::getPanel('admin');
        foreach ($panel->getPages() as $page) {
            if (trim((string) $page::getRoutePath(), '/') === $slug && $page::canAccess()) {
                return $page::getUrl(panel: 'admin');
            }
        }
        foreach ($panel->getResources() as $resource) {
            if ($slug !== '' && trim((string) $resource::getSlug(), '/') === $slug && $resource::canViewAny()) {
                return $resource::getUrl(panel: 'admin');
            }
        }

        return null;
    }
}
