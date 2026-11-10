<?php

namespace App\Filament\Stock\Pages;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\AccessoryCategory;
use App\Domain\Inventory\Services\PriceService;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Ui\Access;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

/** Э9 «Склад — Аксессуары» (/stock/accessories): stock per store, price, average cost by right, scan of a barcode. */
class Accessories extends Page
{
    use HasErpAccess, WithPagination;

    protected static string|array|null $permission = 'stock.passport';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'stock/accessories';

    protected static string $view = 'stock.pages.accessories';

    public string $q = '';

    public ?int $category_id = null;

    public string $stock = '';   // '' | low | none

    public ?int $editId = null;

    public string $newPrice = '';

    public ?string $error = null;

    public function getTitle(): string
    {
        return __('Аксессуары');
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
        if (in_array($name, ['q', 'category_id', 'stock'], true)) {
            $this->resetPage();
        }
    }

    public function scanned(string $code): void
    {
        $this->q = trim($code);
    }

    public function savePrice(): void
    {
        $this->error = null;
        $tenge = (int) preg_replace('/\D/', '', $this->newPrice);
        try {
            if ($tenge <= 0) {
                throw new \InvalidArgumentException(__('Введите цену'));
            }
            app(PriceService::class)->setAccessoryPrice((int) $this->editId, Money::kzt($tenge), auth()->user());
            $this->editId = null;
            $this->newPrice = '';
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function getViewData(): array
    {
        $stores = WorkingStore::available(auth()->user());
        $storeIds = Access::allows('stock.other_stores') ? \App\Domain\Tenancy\Models\Store::query()->pluck('id')->all() : $stores->pluck('id')->all();
        $qtySql = '(SELECT coalesce(sum(b.qty), 0) FROM accessory_balances b WHERE b.accessory_id = accessories.id AND b.location_type = \'store\' AND b.location_id IN ('.(implode(',', array_map('intval', $storeIds)) ?: '0').'))';
        $q = trim($this->q);
        $items = Accessory::query()
            ->select('accessories.*')->selectRaw("$qtySql AS qty")
            ->selectRaw('(SELECT price_kzt FROM accessory_prices p WHERE p.accessory_id = accessories.id) AS price')
            ->when(Access::allows('stock.view_cost'), fn ($w) => $w->selectRaw('(SELECT avg_cost_kzt FROM accessory_costs c WHERE c.accessory_id = accessories.id) AS avg_cost'))
            ->where('is_active', true)
            ->when($this->category_id, fn ($w, $id) => $w->where('category_id', $id))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('barcode', $q)->orWhere('sku', 'ilike', $q.'%')->orWhere('name_ru', 'ilike', '%'.addcslashes($q, '%_').'%')))
            ->when($this->stock === 'none', fn ($w) => $w->whereRaw("$qtySql = 0"))
            ->when($this->stock === 'low', fn ($w) => $w->whereRaw("$qtySql BETWEEN 1 AND 3"))
            ->orderBy('name_ru')->paginate(50);
        $perStore = DB::table('accessory_balances')->where('location_type', 'store')->whereIn('location_id', $storeIds)
            ->whereIn('accessory_id', $items->pluck('id'))->get(['accessory_id', 'location_id', 'qty'])->groupBy('accessory_id');

        return [
            'items' => $items,
            'perStore' => $perStore,
            'storeNames' => \App\Domain\Tenancy\Models\Store::query()->pluck('name', 'id'),
            'categories' => AccessoryCategory::query()->orderBy('name_ru')->get(['id', 'name_ru']),
            'canCost' => Access::allows('stock.view_cost'),
            'canPrice' => Access::allows('price.edit'),
            'multiStore' => count($storeIds) > 1,
        ];
    }
}
