<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/** О8 «Остатки в деньгах». */
class StockValue extends Report
{
    use StockQuery;

    public function key(): string
    {
        return 'stock_value';
    }

    public function code(): string
    {
        return 'О8';
    }

    public function tab(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return 'Остатки в деньгах';
    }

    public function permissions(): array
    {
        return ['report.stock_value', 'report.aging'];
    }

    public function filters(): array
    {
        return ['store', 'cond'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $units = $this->stock($s, ['in_stock', 'reserved', 'ordered', 'preparing', 'in_transit', 'defective']);
        $acc = DB::table('accessory_balances as b')->join('accessories as a', 'a.id', '=', 'b.accessory_id')->leftJoin('accessory_costs as ac', 'ac.accessory_id', '=', 'a.id')
            ->leftJoin('accessory_prices as ap', 'ap.accessory_id', '=', 'a.id')->where('b.location_type', 'store')->whereIn('b.location_id', $s->storeIds ?: [0])->where('b.qty', '>', 0)
            ->selectRaw('sum(b.qty) qty, sum(b.qty * coalesce(ac.avg_cost_kzt, 0)) cost, sum(b.qty * coalesce(ap.price_kzt, 0)) retail')->first();
        $rows = $units->groupBy('model_id')->map(function ($g) {
            $cost = (int) $g->sum('cost_kzt');
            $retail = (int) $g->sum('retail');

            return ['model' => $g->first()->model, 'in_stock' => $g->whereIn('status', ['in_stock', 'reserved', 'ordered'])->count(), 'preparing' => $g->where('status', 'preparing')->count(),
                'transit' => $g->where('status', 'in_transit')->count(), 'defective' => $g->where('status', 'defective')->count(),
                'cost' => $cost, 'retail' => $retail, 'profit' => $retail - $cost, 'profit_pct' => $retail ? round(($retail - $cost) * 100 / $retail, 1) : null,
                'age' => (int) round($g->map(fn ($u) => self::age($u->received_at))->filter(fn ($v) => $v !== null)->avg() ?? 0)];
        })->sortByDesc('cost')->values();
        $cost = (int) $units->sum('cost_kzt') + (int) ($acc->cost ?? 0);
        $retail = (int) $units->sum('retail') + (int) ($acc->retail ?? 0);
        $rate = (float) (DB::table('exchange_rates')->where('organization_id', CurrentOrganization::instance()->requireId())->where('currency', 'USD')->orderByDesc('valid_from')->value('rate') ?? 0);
        $list = fn ($filter) => $units->filter($filter)->map(fn ($u) => ['code' => $u->public_code, 'model' => trim($u->model.' '.$u->memory.' '.$u->color), 'cost' => (int) $u->cost_kzt, 'retail' => (int) $u->retail,
            '_url' => route('erp.stock.units.show', ['code' => $u->public_code])])->values()->all();
        $listCols = ['code' => self::col(__('Код')), 'model' => self::col(__('Телефон')), 'cost' => self::col(__('Себестоимость'), 'money', 'cost'), 'retail' => self::col(__('Розница'), 'money')];

        return [
            'tiles' => [self::tile(__('По себестоимости'), $cost, 'money', 'cost'), self::tile(__('По рознице'), $retail), self::tile(__('Ожидаемая прибыль'), $retail - $cost, 'money', 'cost'),
                self::tile(__('Телефонов / аксессуаров'), $units->count().' / '.(int) ($acc->qty ?? 0), 'text'),
                self::tile(__('В $ по курсу'), $rate ? '$'.number_format($cost / 100 / $rate, 0, '', ' ') : '—', 'text', 'cost')],
            'columns' => ['model' => self::col(__('Модель')), 'in_stock' => self::col(__('В наличии'), 'int'), 'preparing' => self::col(__('На подготовке'), 'int'), 'transit' => self::col(__('В пути'), 'int'),
                'defective' => self::col(__('Брак'), 'int'), 'cost' => self::col(__('Себестоимость'), 'money', 'cost'), 'retail' => self::col(__('Розница'), 'money'),
                'profit' => self::col(__('Ожид. прибыль'), 'money', 'cost'), 'profit_pct' => self::col(__('%'), 'pct', 'cost'), 'age' => self::col(__('Средний возраст, дн.'), 'int')],
            'rows' => $rows->all(),
            'total' => ['model' => __('Итого телефоны'), 'in_stock' => $rows->sum('in_stock'), 'preparing' => $rows->sum('preparing'), 'transit' => $rows->sum('transit'), 'defective' => $rows->sum('defective'),
                'cost' => (int) $units->sum('cost_kzt'), 'retail' => (int) $units->sum('retail'), 'profit' => (int) $units->sum('retail') - (int) $units->sum('cost_kzt'), 'profit_pct' => null, 'age' => null],
            'extra' => [
                ['title' => __('Нет цены'), 'columns' => $listCols, 'rows' => $list(fn ($u) => ! $u->retail)],
                ['title' => __('Себестоимость неизвестна'), 'columns' => $listCols, 'rows' => $list(fn ($u) => $u->cost_unknown)],
                ['title' => __('Цена ниже себестоимости'), 'sensitive' => 'cost', 'columns' => $listCols, 'rows' => $list(fn ($u) => $u->retail && ! $u->cost_unknown && $u->retail < $u->cost_kzt)],
            ],
        ];
    }
}
