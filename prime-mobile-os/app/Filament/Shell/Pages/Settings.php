<?php

namespace App\Filament\Shell\Pages;

use App\Domain\Shell\Services\AuditLog;
use App\Domain\Tenancy\Models\Organization;
use App\Domain\Tenancy\Models\Store;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Э26 «Настройки фирмы» (/settings). The owner changes everything; the admin — only the store data (TZ 04 §1).
 * Values are validated and converted on the server; the browser sends tenge and percent, the database keeps tiyn.
 */
class Settings extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'org.settings';

    protected static ?MenuGroup $menuGroup = MenuGroup::Management;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'settings';

    protected static string $view = 'shell.pages.settings';

    /** @var array<string, mixed> firm settings in user units */
    public array $firm = [];

    /** @var array<int, array{name: string, address: ?string, phone: ?string}> */
    public array $stores = [];

    public static function getNavigationLabel(): string
    {
        return __('Настройки фирмы');
    }

    public function getTitle(): string
    {
        return __('Настройки фирмы');
    }

    public function mount(): void
    {
        $org = CurrentOrganization::instance()->require();
        $s = $org->settings();
        $this->firm = [
            'name' => $org->name,
            'legal_name' => $org->legal_name,
            'bin_iin' => $org->bin_iin,
            'address' => $org->requisites['address'] ?? '',
            'phone' => $org->requisites['phone'] ?? '',
            'instagram' => $org->requisites['instagram'] ?? '',
            'accent' => $s->accent(),
            'bonus_enabled' => $s->bonusEnabled(),
            'bonus_phone' => $s->bonusPhonePercent(),
            'bonus_accessory' => $s->bonusAccessoryPercent(),
            'bonus_activation_days' => $s->bonusActivationDays(),
            'bonus_lifetime_days' => $s->bonusLifetimeDays(),
            'bonus_max_pay' => $s->bonusMaxPayPercent(),
            'require_imei_check' => $s->requireImeiCheck(),
            'imei_max_age_days' => $s->imeiCheckMaxAgeDays(),
            'reservation_minutes' => $s->reservationMinutes(),
            'order_hold_days' => (int) $s->get('sales.order_hold_days'),
            'debt_ceiling' => intdiv($s->customerDebtCeiling(), 100),
            'backdate_days' => $s->backdateMaxDays(),
            'cash_threshold' => intdiv((int) $s->get('cash.discrepancy_threshold'), 100),
            'code_prefix' => (string) $s->get('storefront.code_prefix'),
        ];
        foreach (Store::query()->orderBy('id')->get() as $store) {
            $this->stores[$store->id] = ['name' => $store->name, 'address' => $store->address, 'phone' => $store->phone];
        }
    }

    public function saveFirm(): void
    {
        Access::authorize('org.security', __('Настройки фирмы меняет владелец'));
        $f = $this->validate([
            'firm.name' => 'required|string|max:80',
            'firm.legal_name' => 'nullable|string|max:120',
            'firm.bin_iin' => 'nullable|digits:12',
            'firm.address' => 'nullable|string|max:200',
            'firm.phone' => 'nullable|string|max:30',
            'firm.instagram' => 'nullable|string|max:60',
            'firm.accent' => 'required|in:blue,graphite',
            'firm.bonus_enabled' => 'boolean',
            'firm.bonus_phone' => 'required|numeric|min:0|max:20',
            'firm.bonus_accessory' => 'required|numeric|min:0|max:30',
            'firm.bonus_activation_days' => 'required|integer|min:0|max:60',
            'firm.bonus_lifetime_days' => 'required|integer|min:30|max:1095',
            'firm.bonus_max_pay' => 'required|numeric|min:0|max:100',
            'firm.require_imei_check' => 'boolean',
            'firm.imei_max_age_days' => 'required|integer|min:1|max:60',
            'firm.reservation_minutes' => 'required|integer|min:5|max:240',
            'firm.order_hold_days' => 'required|integer|min:1|max:30',
            'firm.debt_ceiling' => 'required|integer|min:0|max:100000000',
            'firm.backdate_days' => 'required|integer|min:0|max:31',
            'firm.cash_threshold' => 'required|integer|min:0|max:10000000',
            'firm.code_prefix' => 'required|regex:/^[A-Z]{2,4}$/',
        ])['firm'];

        DB::transaction(function () use ($f) {
            $org = Organization::query()->whereKey(CurrentOrganization::instance()->requireId())->lockForUpdate()->firstOrFail();
            $old = $org->settings;
            // the public code prefix is printed on price tags: it may change only before the first phone (audit №43)
            if ($f['code_prefix'] !== (string) $org->settings()->get('storefront.code_prefix')
                && DB::table('stock_units')->where('organization_id', $org->id)->exists()) {
                throw \App\Support\Exceptions\UserFacingException::because('prefix_locked', __('Приставку кода нельзя менять: коды уже напечатаны на ценниках'));
            }
            $settings = array_replace_recursive($org->settings ?? [], [
                'accent' => $f['accent'],
                'bonus' => [
                    'enabled' => (bool) $f['bonus_enabled'], 'phone_percent' => number_format((float) $f['bonus_phone'], 2, '.', ''),
                    'accessory_percent' => number_format((float) $f['bonus_accessory'], 2, '.', ''),
                    'activation_days' => (int) $f['bonus_activation_days'], 'lifetime_days' => (int) $f['bonus_lifetime_days'],
                    'max_pay_percent' => number_format((float) $f['bonus_max_pay'], 2, '.', ''),
                ],
                'sales' => [
                    'require_imei_check' => (bool) $f['require_imei_check'], 'imei_check_max_age_days' => (int) $f['imei_max_age_days'],
                    'reservation_minutes' => (int) $f['reservation_minutes'], 'order_hold_days' => (int) $f['order_hold_days'],
                    'customer_debt_ceiling' => (int) $f['debt_ceiling'] * 100,
                ],
                'documents' => ['backdate_max_days' => (int) $f['backdate_days']],
                'cash' => ['discrepancy_threshold' => (int) $f['cash_threshold'] * 100],
                'storefront' => ['code_prefix' => $f['code_prefix']],
            ]);
            $org->forceFill([
                'name' => $f['name'], 'legal_name' => $f['legal_name'], 'bin_iin' => $f['bin_iin'], 'settings' => $settings,
                'requisites' => array_replace($org->requisites ?? [], ['address' => $f['address'], 'phone' => $f['phone'], 'instagram' => $f['instagram']]),
            ])->save();
            AuditLog::record('settings', 'firm_settings_changed', $org, ['before' => $old, 'after' => $settings]);
            CurrentOrganization::instance()->set($org);
        });
        Notification::make()->success()->title(__('Настройки сохранены'))->send();
    }

    public function saveStores(): void
    {
        Access::authorize('org.settings', __('Данные магазина меняют админ и владелец'));
        $this->validate([
            'stores.*.name' => 'required|string|max:80',
            'stores.*.address' => 'nullable|string|max:200',
            'stores.*.phone' => 'nullable|string|max:30',
        ]);
        foreach ($this->stores as $id => $data) {
            $store = Store::query()->findOrFail((int) $id); // only stores of the current firm
            $store->fill(['name' => $data['name'], 'address' => $data['address'], 'phone' => $data['phone']])->save();
        }
        Notification::make()->success()->title(__('Данные магазинов сохранены'))->send();
    }

    protected function getViewData(): array
    {
        return ['isOwner' => Access::isOwner()];
    }
}
