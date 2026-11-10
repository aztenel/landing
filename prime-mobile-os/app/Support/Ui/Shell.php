<?php

namespace App\Support\Ui;

use App\Domain\Shell\Services\DeviceRegistry;
use App\Domain\Shell\Services\ScreenLock;
use App\Domain\Tenancy\Models\Organization;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Spatie\Permission\Models\Role;

/**
 * Data of the ERP frame for the layout views (sidebar, topbar, iPhone tab bar). Keeps Blade simple.
 */
final class Shell
{
    /**
     * iPhone tab bar by role (TZ 04 §0.3): 4 places by address + the round «Скан» in the middle.
     * A missing page (module not installed yet / no right) is replaced by the next menu item.
     */
    public const TABS = [
        'seller' => ['sale', 'stock/phones', 'scan', 'customers', 'more'],
        'senior_seller' => ['sale', 'stock/phones', 'scan', 'customers', 'more'],
        'admin' => ['sale', 'stock/phones', 'scan', 'debts', 'more'],
        'owner' => ['', 'requests', 'scan', 'stock/phones', 'more'],
        'accountant' => ['', 'balance', null, 'debts', 'more'],
    ];

    public static function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function organization(): ?Organization
    {
        return CurrentOrganization::instance()->get();
    }

    public static function role(): ?Role
    {
        $user = self::user();
        if (! $user || ! CurrentOrganization::instance()->has()) {
            return null;
        }
        $user->unsetRelation('roles');

        return $user->roles()->first();
    }

    public static function accent(): Accent
    {
        return Accent::fromSetting(self::organization()?->settings()->accent());
    }

    public static function initials(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $letters = array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice(array_filter($parts), 0, 2));

        return implode('', $letters) ?: '•';
    }

    /** @return array{0: string, 1: string} two lines of the store plate */
    public static function storePlate(): array
    {
        $org = self::organization();
        if (! $org) {
            return ['Prime Mobile', ''];
        }
        $stores = Store::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get(['id', 'name']);
        $first = $stores->first()?->name ?? '';
        $count = $stores->count();
        $label = trans_choice(':count магазин|:count магазина|:count магазинов', $count, ['count' => $count]);

        // the store this person works in (cash register, shift) comes first, like on the mockup «ТРЦ «Керуен» · Смена с 09:00»
        $working = ($user = self::user()) ? WorkingStore::get($user) : null;
        if ($working && $count > 1 || $working && ! self::role()?->is_owner) {
            return [$working->name, $org->settings()->get('brand.display_name') ?: $org->name];
        }

        return [$org->settings()->get('brand.display_name') ?: $org->name, trim($first.' · '.$label, ' ·')];
    }

    /** @return list<array{label: string, icon: ?string, url: string, active: bool, badge: mixed}> */
    public static function tabs(): array
    {
        $role = self::role();
        $user = self::user();
        $slots = self::TABS[$role?->name ?? ''] ?? ($role?->is_owner ? self::TABS['owner'] : self::TABS['seller']);
        $items = self::flatNavigation();
        $byPath = [];
        foreach ($items as $item) {
            $byPath[trim((string) parse_url((string) $item->getUrl(), PHP_URL_PATH), '/')] = $item;
        }
        $used = [];
        $tabs = [];
        foreach ($slots as $slot) {
            if ($slot === 'scan' || $slot === null) {
                $tabs[] = $slot === 'scan' && $user ? ['scan' => true] : ['spacer' => true];

                continue;
            }
            if ($slot === 'more') {
                $tabs[] = ['label' => __('Ещё'), 'icon' => 'dots', 'url' => url('/more'), 'active' => request()->is('more'), 'badge' => null];

                continue;
            }
            $item = $byPath[$slot] ?? null;
            if (! $item) {
                foreach ($items as $candidate) {
                    $path = trim((string) parse_url((string) $candidate->getUrl(), PHP_URL_PATH), '/');
                    if (! in_array($path, $used, true) && ! in_array($path, $slots, true)) {
                        $item = $candidate;
                        break;
                    }
                }
            }
            if (! $item) {
                $tabs[] = ['spacer' => true];

                continue;
            }
            $used[] = trim((string) parse_url((string) $item->getUrl(), PHP_URL_PATH), '/');
            $tabs[] = ['label' => $item->getLabel(), 'icon' => $item->getIcon(), 'url' => $item->getUrl(), 'active' => $item->isActive(), 'badge' => $item->getBadge()];
        }

        return $tabs;
    }

    /** @return list<NavigationItem> */
    public static function flatNavigation(): array
    {
        $out = [];
        foreach (filament()->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** JS config of the frame: lock timeout, endpoints, legacy flag. */
    public static function jsConfig(): array
    {
        $user = self::user();
        $device = CurrentOrganization::instance()->has() ? app(DeviceRegistry::class)->current() : null;
        $lockMinutes = $user && CurrentOrganization::instance()->has()
            ? app(ScreenLock::class)->timeoutMinutes($user, $device, request()) : null;

        return [
            'user' => $user ? ['name' => $user->name, 'first' => explode(' ', trim($user->name))[0]] : null,
            'lockMinutes' => $lockMinutes,
            'warnSeconds' => (int) config('prime.lock.warning_seconds', 30),
            'routes' => $user ? [
                'lock' => route('erp.shell.lock'),
                'unlock' => route('erp.shell.unlock'),
                'activity' => route('erp.shell.activity'),
                'logout' => Filament::getLogoutUrl(),
                'unit' => url('/unit'),
            ] : null,
            'legacy' => $device?->legacy_mode,
            'oldChrome' => DeviceRegistry::isOldChrome((string) request()->userAgent()),
        ];
    }
}
