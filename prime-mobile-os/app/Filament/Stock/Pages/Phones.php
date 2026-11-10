<?php

namespace App\Filament\Stock\Pages;

use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\VariantPrice;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Enums\ImeiCheckResult;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Queries\UnitList;
use App\Domain\Inventory\Services\ImeiCheckService;
use App\Domain\Inventory\Services\PriceService;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;
use Livewire\WithPagination;

/**
 * Э8 «Склад — Электроника» (/stock). Everyone sees the phones; cost, margin and supplier only with the rights
 * (they are not even selected into the view without them). Bulk: госбаза, price, labels (printing module), Excel.
 */
class Phones extends Page
{
    use HasErpAccess, WithPagination;

    protected static string|array|null $permission = 'stock.passport';

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'box';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'stock';

    protected static string $view = 'stock.pages.phones';

    public string $tab = 'in_stock';

    public string $q = '';

    public ?int $model_id = null;

    public string $condition = '';

    public ?int $store_id = null;

    public ?int $supplier_id = null;

    public string $imei = '';

    public ?int $age = null;

    public bool $no_cost = false;

    /** @var list<int> */
    public array $selected = [];

    public ?string $modal = null;

    public string $newPrice = '';

    public ?string $error = null;

    public ?string $flash = null;

    protected $queryString = ['tab' => ['except' => 'in_stock'], 'q' => ['except' => '']];

    public static function getNavigationLabel(): string
    {
        return __('Склад');
    }

    public function getTitle(): string
    {
        return __('Склад');
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function updated(string $name): void
    {
        if ($name !== 'selected' && $name !== 'newPrice' && $name !== 'modal') {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function filters(): array
    {
        return ['tab' => $this->tab, 'q' => $this->q, 'model_id' => $this->model_id, 'condition' => $this->condition, 'store_id' => $this->store_id,
            'supplier_id' => $this->supplier_id, 'imei' => $this->imei, 'age' => $this->age, 'no_cost' => $this->no_cost];
    }

    public function resetFilters(): void
    {
        $this->reset(['q', 'model_id', 'condition', 'store_id', 'supplier_id', 'imei', 'age', 'no_cost', 'selected']);
    }

    /** Scan in the list = open the passport at once (TZ 04 Э8 iPhone). */
    public function openByCode(string $code): void
    {
        $unit = StockUnit::query()->where('public_code', mb_strtoupper(trim($code)))->first() ?? StockUnit::findByIdentifier($code);
        if ($unit) {
            $this->redirect(route('erp.stock.units.show', $unit->public_code ?? $unit->id));

            return;
        }
        $this->q = $code;
    }

    public function markImei(): void
    {
        $this->error = null;
        $done = 0;
        try {
            foreach (StockUnit::query()->whereIn('id', $this->selected)->whereNotNull('imei1')->get() as $unit) {
                app(ImeiCheckService::class)->record($unit, ImeiCheckResult::Registered, auth()->user(), 'manual', __('Отмечено списком на складе'));
                $done++;
            }
            $this->flash = __('Отмечено в госбазе: :n шт.', ['n' => $done]);
            $this->selected = [];
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function changePrice(): void
    {
        $this->error = null;
        $tenge = (int) preg_replace('/\D/', '', $this->newPrice);
        if ($tenge <= 0) {
            $this->error = __('Введите новую цену');

            return;
        }
        try {
            foreach (StockUnit::query()->whereIn('id', $this->selected)->get() as $unit) {
                app(PriceService::class)->setUnitPrice($unit, Money::kzt($tenge), auth()->user());
            }
            $this->flash = __('Цена изменена: :n шт.', ['n' => count($this->selected)]);
            $this->selected = [];
            $this->modal = null;
            $this->newPrice = '';
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $base = UnitList::query($this->filters(), $user);
        $canCost = Access::allows('stock.view_cost');
        $agg = (clone $base)->reorder()->toBase()->selectRaw('count(*) as n, coalesce(sum(cost_kzt), 0) as cost, coalesce(sum(coalesce(sale_price, (select price_kzt from variant_prices vp where vp.variant_id = stock_units.variant_id and vp.organization_id = stock_units.organization_id))), 0) as retail')->first();
        $stores = WorkingStore::available($user);

        return [
            'units' => $base->paginate(50),
            'counts' => UnitList::counts($user),
            'sum' => ['n' => (int) $agg->n, 'cost' => $canCost ? (int) $agg->cost : null, 'retail' => (int) $agg->retail],
            'noImei' => (int) UnitList::query(['tab' => 'in_stock', 'imei' => 'none'], $user)->reorder()->count(),
            'old' => (int) UnitList::query(['tab' => 'in_stock', 'age' => 30], $user)->reorder()->count(),
            'models' => ProductModel::query()->whereIn('id', \App\Domain\Catalog\Models\Variant::query()->whereIn('id', StockUnit::query()->select('variant_id'))->select('model_id'))->orderBy('name_ru')->get(['id', 'name_ru']),
            'suppliers' => Access::allows('stock.view_supplier') ? Supplier::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'stores' => $stores,
            'storeNames' => $stores->pluck('name', 'id'),
            'canCost' => $canCost,
            'canSupplier' => Access::allows('stock.view_supplier'),
            'canPrice' => Access::allows('price.edit'),
            'canImei' => Access::allows('stock.imei_check'),
            'canReceive' => Access::allows('stock.receive'),
            'variantPrices' => VariantPrice::query()->pluck('price_kzt', 'variant_id'),
            'maxAge' => CurrentOrganization::instance()->require()->settings()->imeiCheckMaxAgeDays(),
            'exportUrl' => route('erp.stock.export', array_filter($this->filters(), fn ($v) => $v !== null && $v !== '' && $v !== false)),
        ];
    }
}
