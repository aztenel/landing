<?php

namespace App\Filament\Stock\Pages;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Catalog\Models\VariantPrice;
use App\Domain\Inventory\Models\PriceChange;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Services\PriceService;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

/**
 * Э13 «Прайс» (/prices): retail price of each variant, edited right in the table; «Применить…» with a reason, an
 * option to update the phones already in stock that have their own price. Every change goes through PriceService
 * (limit L14, journal price_changes). More than one price at once — «Массовая смена» (price.bulk).
 */
class Prices extends Page
{
    use HasErpAccess, WithPagination;

    protected static string|array|null $permission = 'stock.passport';

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'receipt';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'prices';

    protected static string $view = 'stock.pages.prices';

    public string $q = '';

    public bool $inStockOnly = true;

    public string $tabName = 'prices';

    /** @var array<int, string> variant id => new price in tenge (edited cells) */
    public array $changes = [];

    public bool $applyOpen = false;

    public string $reason = 'переоценка';

    public bool $updateUnits = true;

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Прайс');
    }

    public function getTitle(): string
    {
        return __('Прайс');
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['q', 'inStockOnly'], true)) {
            $this->resetPage();
        }
    }

    public function discard(): void
    {
        $this->changes = [];
        $this->applyOpen = false;
    }

    /** @return array<int, int> variant id => tiyn of the real changes */
    protected function pending(): array
    {
        $current = VariantPrice::query()->whereIn('variant_id', array_keys($this->changes))->pluck('price_kzt', 'variant_id');
        $out = [];
        foreach ($this->changes as $id => $value) {
            $tenge = (int) preg_replace('/\D/', '', (string) $value);
            if ($tenge > 0 && $tenge * Money::TENGE !== (int) ($current[$id] ?? 0)) {
                $out[(int) $id] = $tenge * Money::TENGE;
            }
        }

        return $out;
    }

    public function apply(): void
    {
        $this->error = null;
        $pending = $this->pending();
        if ($pending === []) {
            $this->error = __('Нет изменённых цен');

            return;
        }
        try {
            if (count($pending) > 1) {
                Access::authorize('price.bulk'); // admin: «только заявкой» → AccessDenied with the text of the role
            }
            if (trim($this->reason) === '') {
                throw new \InvalidArgumentException(__('Укажите причину'));
            }
            $units = 0;
            DB::transaction(function () use ($pending, &$units) {
                $user = auth()->user();
                foreach ($pending as $variantId => $tiyn) {
                    app(PriceService::class)->setVariantPrice($variantId, Money::kztMinor($tiyn), $user, 'manual');
                    if ($this->updateUnits) {
                        // new phones of this variant in stock with their own price follow the price list
                        foreach (StockUnit::query()->where('variant_id', $variantId)->where('condition', 'new')
                            ->whereIn('status', ['in_stock', 'reserved', 'preparing', 'on_check'])->whereNotNull('sale_price')->get() as $unit) {
                            app(PriceService::class)->setUnitPrice($unit, Money::kztMinor($tiyn), $user, 'manual');
                            $units++;
                        }
                    }
                }
            });
            activity('prices')->causedBy(auth()->user())->withProperties(['variants' => count($pending), 'units' => $units, 'reason' => $this->reason])->log('prices_changed');
            $this->flash = __('Цены изменены: :v вариантов, :u телефонов. Сайт обновится в течение минуты.', ['v' => count($pending), 'u' => $units]);
            $this->changes = [];
            $this->applyOpen = false;
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function getViewData(): array
    {
        $q = trim($this->q);
        $stock = 'SELECT count(*) FROM stock_units u WHERE u.variant_id = variants.id AND u.organization_id = ? AND u.status IN (\'in_stock\',\'reserved\',\'preparing\')';
        $orgId = \App\Support\Tenancy\CurrentOrganization::instance()->requireId();
        $variants = Variant::query()->select('variants.*')
            ->selectRaw("($stock) AS in_stock", [$orgId])
            ->with(['productModel', 'memory', 'color'])
            ->when($this->inStockOnly, fn ($w) => $w->where(fn ($s) => $s->whereRaw("($stock) > 0", [$orgId])->orWhereIn('variants.id', VariantPrice::query()->select('variant_id'))))
            ->when($q !== '', fn ($w) => $w->whereHas('productModel', fn ($m) => $m->where('name_ru', 'ilike', '%'.addcslashes($q, '%_').'%')))
            ->join('models', 'models.id', '=', 'variants.model_id')->orderBy('models.name_ru')->orderBy('variants.id')
            ->paginate(50);
        $prices = VariantPrice::query()->whereIn('variant_id', $variants->pluck('id'))->get()->keyBy('variant_id');

        return [
            'variants' => $variants,
            'prices' => $prices,
            'pending' => $this->pending(),
            'canEdit' => Access::allows('price.edit'),
            'history' => $this->tabName === 'history' ? PriceChange::query()->latest('id')->limit(100)->get() : collect(),
            'users' => \App\Models\User::query()->pluck('name', 'id'),
        ];
    }
}
