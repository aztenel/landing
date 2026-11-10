<?php

namespace App\Filament\TradeIn\Pages;

use App\Domain\Counterparties\Models\Customer;
use App\Domain\Ledger\Models\Document;
use App\Domain\TradeIn\Models\TradeInAct;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/** Э6 list of acts (/tradein): date, model, grade, sum, way of payment, above the grid, who took it. */
class TradeIns extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'tradein.create';

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'swap';

    protected static ?int $navigationSort = 16;

    protected static ?string $slug = 'tradein';

    protected static string $view = 'tradein.pages.list';

    public bool $overGrid = false;

    public static function getNavigationLabel(): string
    {
        return __('Trade-in');
    }

    public function getTitle(): string
    {
        return __('Trade-in');
    }

    protected function getViewData(): array
    {
        $docs = Document::query()->where('type', 'tradein')->whereIn('status', ['posted', 'reversed'])
            ->whereIn('store_id', WorkingStore::available(auth()->user())->pluck('id'))->latest('id')->limit(100)->get();
        $acts = TradeInAct::query()->with([])->whereIn('document_id', $docs->pluck('id'))->get()->keyBy('document_id');
        if ($this->overGrid) {
            $docs = $docs->filter(fn ($d) => ($a = $acts[$d->id] ?? null) && ($a->grid_price === null || $a->amount > $a->grid_price));
        }

        return [
            'docs' => $docs, 'acts' => $acts,
            'variants' => \App\Domain\Catalog\Models\Variant::query()->with(['productModel', 'memory', 'color'])->whereIn('id', $acts->pluck('variant_id'))->get()->keyBy('id'),
            'customers' => Customer::query()->whereIn('id', $acts->pluck('customer_id'))->pluck('name', 'id'),
            'users' => \App\Models\User::query()->whereIn('id', $docs->pluck('posted_by'))->pluck('name', 'id'),
        ];
    }
}
