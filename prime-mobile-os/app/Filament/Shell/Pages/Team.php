<?php

namespace App\Filament\Shell\Pages;

use App\Domain\Shell\Models\Device;
use App\Domain\Shell\Services\DeviceRegistry;
use App\Domain\Shell\Services\RoleEditor;
use App\Domain\Shell\Services\StaffManager;
use App\Domain\Shell\Services\TwoFactor;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Store;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Phone\Phone;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Spatie\Permission\Models\Role;

/**
 * Э25 «Сотрудники и роли» (/team): staff (invite, block, dismiss, password link), roles (matrix of rights with limits,
 * as erp-4-sotrudniki-i-roli.png), devices. Every action checks the right on the server inside the services.
 */
class Team extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = ['staff.manage', 'role.manage', 'device.manage'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Management;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'team';

    protected static string $view = 'shell.pages.team';

    public string $tab = 'staff';

    // invite form
    public string $inviteName = '';

    public string $invitePhone = '';

    public ?int $inviteRole = null;

    /** @var list<int> */
    public array $inviteStores = [];

    public ?string $inviteLink = null;

    // role editor
    public ?int $roleId = null;

    /** @var array<string, array{on: bool, approval: bool}> */
    public array $state = [];

    /** @var array<string, string|null> limits in user units (₸, %, days) */
    public array $limits = [];

    public static function getNavigationLabel(): string
    {
        return __('Сотрудники и роли');
    }

    public function getTitle(): string
    {
        return __('Сотрудники и роли');
    }

    public function mount(): void
    {
        $this->tab = Access::allows('staff.manage') ? 'staff' : (Access::allows('role.manage') ? 'roles' : 'devices');
        $this->inviteStores = Store::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->loadRole(collect(app(RoleEditor::class)->roles())->firstWhere('is_owner', false)?->id);
    }

    // ------------------------------------------------------------------ staff

    public function invite(): void
    {
        try {
            $result = app(StaffManager::class)->invite([
                'name' => $this->inviteName, 'phone' => $this->invitePhone, 'role_id' => (int) $this->inviteRole,
                'store_ids' => $this->inviteStores,
            ], auth()->user());
            $this->inviteLink = $result['url'];
            $this->reset('inviteName', 'invitePhone');
            Notification::make()->success()->title(__('Приглашение готово — отправьте ссылку в WhatsApp'))->send();
        } catch (UserFacingException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function staffAction(int $membershipId, string $action): void
    {
        $membership = Membership::query()->findOrFail($membershipId); // scoped to the current firm
        $manager = app(StaffManager::class);
        try {
            match ($action) {
                'block' => $manager->block($membership, null, auth()->user()),
                'unblock' => $manager->unblock($membership, auth()->user()),
                'dismiss' => $manager->dismiss($membership, null, auth()->user()),
                'restore' => $manager->restore($membership, auth()->user()),
                'password' => $this->inviteLink = $manager->passwordLink($membership, auth()->user()),
                'logout' => $manager->logoutEverywhere($membership, auth()->user()),
                // TZ 03 §7.5 п. 2: lost the phone with the app — the owner resets 2FA, the person sets it up again
                '2fa' => Access::isOwner() ? app(TwoFactor::class)->reset(User::query()->findOrFail($membership->user_id), auth()->user())
                    : throw UserFacingException::because('owner_only', __('Сбросить вход по коду может только владелец')),
            };
            Notification::make()->success()->title(__('Готово'))->send();
        } catch (UserFacingException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    // ------------------------------------------------------------------ roles

    public function loadRole(?int $roleId): void
    {
        $this->roleId = $roleId;
        $this->state = [];
        $this->limits = [];
        if (! $roleId) {
            return;
        }
        $role = Role::query()->where('organization_id', CurrentOrganization::instance()->requireId())->findOrFail($roleId);
        foreach (app(RoleEditor::class)->matrix($role) as $group) {
            foreach ($group['items'] as $item) {
                // Livewire paths use dots: «sale.create» is stored as «sale__create» (see key())
                $this->state[self::key($item['code'])] = ['on' => $item['on'], 'approval' => $item['approval']];
                foreach ($item['limits'] as $limit) {
                    $this->limits[$limit['code']] = $limit['value'] === null ? null : (string) $this->fromStorage($limit['unit'], (int) $limit['value']);
                }
            }
        }
    }

    public function saveRole(): void
    {
        $role = Role::query()->where('organization_id', CurrentOrganization::instance()->requireId())->findOrFail($this->roleId);
        $units = $this->limitUnits($role);
        $values = [];
        foreach ($this->limits as $code => $value) {
            $values[$code] = $value === null || trim((string) $value) === '' ? null : $this->toStorage($units[$code] ?? 'money', (string) $value);
        }
        try {
            $state = [];
            foreach ($this->state as $key => $row) {
                $state[str_replace('__', '.', $key)] = ['on' => (bool) ($row['on'] ?? false), 'approval' => (bool) ($row['approval'] ?? false)];
            }
            $count = app(RoleEditor::class)->save($role, $state, $values, auth()->user());
            Notification::make()->success()->title(__('Роль сохранена. Изменение коснулось сотрудников: :n', ['n' => $count]))->send();
        } catch (UserFacingException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    // ------------------------------------------------------------------ devices

    public function deviceAction(int $deviceId, string $action): void
    {
        $device = Device::query()->findOrFail($deviceId);
        try {
            $action === 'disable'
                ? app(DeviceRegistry::class)->disable($device, auth()->user())
                : app(DeviceRegistry::class)->enable($device, auth()->user());
            Notification::make()->success()->title(__('Готово'))->send();
        } catch (UserFacingException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    protected function getViewData(): array
    {
        $roles = app(RoleEditor::class)->roles();
        $role = $this->roleId ? collect($roles)->firstWhere('id', $this->roleId) : null;

        return [
            'members' => Membership::query()->with(['user', 'stores'])->orderBy('id')->get(),
            'roles' => $roles,
            'stores' => Store::query()->orderBy('id')->get(),
            'matrix' => $role ? app(RoleEditor::class)->matrix($role) : [],
            'role' => $role,
            'devices' => Access::allows('device.manage') ? Device::query()->with('lastUser')->orderByDesc('last_seen_at')->get() : collect(),
            'staff' => app(StaffManager::class),
            'can' => ['staff' => Access::allows('staff.manage'), 'roles' => Access::allows('role.manage'), 'devices' => Access::allows('device.manage')],
            'phone' => fn (?string $p) => $p ? Phone::format($p) : '',
        ];
    }

    public static function key(string $permission): string
    {
        return str_replace('.', '__', $permission);
    }

    private function limitUnits(Role $role): array
    {
        $units = [];
        foreach (app(RoleEditor::class)->matrix($role) as $group) {
            foreach ($group['items'] as $item) {
                foreach ($item['limits'] as $limit) {
                    $units[$limit['code']] = $limit['unit'];
                }
            }
        }

        return $units;
    }

    private function fromStorage(string $unit, int $value): int|float
    {
        return match ($unit) {
            'money' => intdiv($value, 100),
            'percent' => $value / 100,
            default => $value,
        };
    }

    private function toStorage(string $unit, string $value): int
    {
        $n = (float) str_replace([' ', ','], ['', '.'], $value);

        return match ($unit) {
            'money' => (int) round($n) * 100,
            'percent' => (int) round($n * 100),
            default => (int) round($n),
        };
    }
}
