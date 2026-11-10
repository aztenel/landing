<?php

namespace App\Filament\Shell\Pages;

use App\Domain\Tenancy\Models\Membership;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

/**
 * Э27 «Журнал действий» (/audit): who did what and when, with filters by employee, kind and period. The journal is
 * append-only in the database (audit №14). The admin sees his own store; the owner and the accountant — everything.
 */
class AuditJournal extends Page
{
    use HasErpAccess, WithPagination;

    protected static string|array|null $permission = 'audit.view';

    protected static ?MenuGroup $menuGroup = MenuGroup::Management;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'audit';

    protected static string $view = 'shell.pages.audit';

    public ?int $userId = null;

    public string $kind = '';

    public string $from = '';

    public string $to = '';

    public const KINDS = [
        'documents' => 'Документы', 'stock' => 'Склад', 'auth' => 'Вход и выход', 'settings' => 'Настройки и сотрудники',
        'devices' => 'Устройства', 'ledger' => 'Сверка учёта',
    ];

    /** Russian wording of journal events (the log stores short English codes). */
    public const EVENTS = [
        'login' => 'вошёл', 'logout' => 'вышел', 'login_failed' => 'неверный пароль', 'login_throttled' => 'вход заблокирован после попыток',
        'login_refused' => 'вход запрещён', 'registered' => 'новое устройство', 'disabled' => 'устройство отключено', 'enabled' => 'устройство включено',
        'legacy_mode' => 'режим «старый ноутбук»', 'posted' => 'провёл документ', 'reversed' => 'сторнировал документ',
        'status_changed' => 'изменил статус телефона', 'member_invited' => 'пригласил сотрудника', 'member_blocked' => 'заблокировал сотрудника',
        'member_dismissed' => 'уволил сотрудника', 'role_saved' => 'изменил роль', 'firm_settings_changed' => 'изменил настройки фирмы',
        'reconciliation_failed' => 'ночная сверка нашла расхождения', 'locked' => 'экран заблокирован', 'unlocked' => 'экран разблокирован',
    ];

    public static function getNavigationLabel(): string
    {
        return __('Журнал действий');
    }

    public function getTitle(): string
    {
        return __('Журнал действий');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    protected function getViewData(): array
    {
        $org = CurrentOrganization::instance()->require();
        $tz = $org->timezone;
        $q = DB::table('activity_log')->where('organization_id', $org->id);

        // the admin sees only his stores (TZ 04 §1: «свой магазин»)
        if (! Access::isOwner() && ! Access::allows('report.dashboard')) {
            $stores = Membership::query()->where('user_id', auth()->id())->first()?->stores()->pluck('store_id')->all() ?? [];
            $q->where(fn ($w) => $w->whereIn('store_id', $stores)->orWhere('causer_id', auth()->id()));
        }
        if ($this->userId) {
            $q->where('causer_id', $this->userId);
        }
        if ($this->kind !== '') {
            $q->where('log_name', $this->kind);
        }
        if ($this->from !== '') {
            $q->where('created_at', '>=', \Carbon\CarbonImmutable::parse($this->from, $tz)->startOfDay()->utc());
        }
        if ($this->to !== '') {
            $q->where('created_at', '<', \Carbon\CarbonImmutable::parse($this->to, $tz)->addDay()->startOfDay()->utc());
        }

        $rows = $q->orderByDesc('id')->paginate(50);
        $users = \App\Models\User::query()->whereIn('id', Membership::query()->pluck('user_id'))->orderBy('name')->get(['id', 'name']);

        return ['rows' => $rows, 'users' => $users, 'names' => $users->pluck('name', 'id'), 'tz' => $tz];
    }
}
