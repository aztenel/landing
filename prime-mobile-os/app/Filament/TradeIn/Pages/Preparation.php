<?php

namespace App\Filament\TradeIn\Pages;

use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Services\PriceService;
use App\Domain\Inventory\Services\UnitStatusService;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\TradeIn\Drafts\PrepCostDraft;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/**
 * Э12 «На подготовке» (/stock/prep): used phones in preparation or on check, days, cost with costs (by right), what is
 * not ready. «+ Затрата» (ЗПД), «Готов к продаже» — only with a price and a fresh «Верифицирован» of госбаза.
 */
class Preparation extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'stock.passport';

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'settings';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'stock/prep';

    protected static string $view = 'tradein.pages.prep';

    public ?int $costFor = null;

    public array $cost = ['kind' => 'battery', 'amount' => '', 'pay' => 'account', 'account_id' => null, 'supplier_id' => null, 'due' => '', 'note' => ''];

    public array $prices = [];

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('На подготовке');
    }

    public function getTitle(): string
    {
        return __('На подготовке');
    }

    public function saveCost(): void
    {
        $this->error = null;
        try {
            $unit = StockUnit::query()->findOrFail($this->costFor);
            $amount = Money::kzt((int) preg_replace('/\D/', '', (string) $this->cost['amount']));
            $draft = $this->cost['pay'] === 'master'
                ? PrepCostDraft::owed($unit, $this->cost['kind'], $amount, (int) $this->cost['supplier_id'], \Carbon\CarbonImmutable::parse($this->cost['due'] ?: now()->addDays(14)->toDateString()), $this->cost['note'] ?: null)
                : PrepCostDraft::paid($unit, $this->cost['kind'], $amount, (int) $this->cost['account_id'], $this->cost['note'] ?: null);
            $draft->post(auth()->user());
            $this->costFor = null;
            $this->flash = __('Затрата добавлена');
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function ready(int $unitId): void
    {
        $this->error = null;
        try {
            $unit = StockUnit::query()->findOrFail($unitId);
            $price = (int) preg_replace('/\D/', '', (string) ($this->prices[$unitId] ?? ''));
            if ($price > 0) {
                app(PriceService::class)->setUnitPrice($unit, Money::kzt($price), auth()->user());
            }
            $unit->refresh();
            $check = $unit->imei1 ? $unit->latestImeiCheck() : null;
            $days = CurrentOrganization::instance()->require()->settings()->imeiCheckMaxAgeDays();
            if (! $unit->sale_price) {
                throw new \InvalidArgumentException(__('Укажите цену продажи'));
            }
            if ($unit->imei1 && (! $check || $check->result->value !== 'registered' || $check->checked_at->lt(now()->subDays($days)))) {
                throw new \InvalidArgumentException(__('Нужна свежая отметка госбазы «Верифицирован» — проверьте в паспорте'));
            }
            app(UnitStatusService::class)->markReady($unit, auth()->user());
            $this->flash = __('Телефон :c готов к продаже', ['c' => $unit->public_code]);
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function getViewData(): array
    {
        $stores = WorkingStore::available(auth()->user())->pluck('id');

        return [
            'units' => StockUnit::query()->with(['variant.productModel', 'variant.memory', 'variant.color'])
                ->whereIn('status', [UnitStatus::Preparing->value, UnitStatus::OnCheck->value])
                ->where('location_type', 'store')->whereIn('location_id', $stores)->orderBy('received_at')->get(),
            'canCost' => Access::allows('stock.view_cost'),
            'canAddCost' => Access::allows('stock.prepare_cost'),
            'canReady' => Access::allows('stock.prepare'),
            'accounts' => MoneyAccount::query()->where('currency', 'KZT')->whereIn('type', ['cash', 'bank', 'safe'])->where('is_active', true)->get(['id', 'name']),
            'masters' => Supplier::query()->where('is_active', true)->orderByDesc('is_repair_master')->orderBy('name')->get(['id', 'name']),
        ];
    }
}
