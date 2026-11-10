<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use Illuminate\Support\Facades\DB;

/** О11 «Заканчивается / предзакуп». */
class RunningLow extends Report
{
    use StockQuery;

    public function key(): string
    {
        return 'low';
    }

    public function code(): string
    {
        return 'О11';
    }

    public function tab(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return 'Заканчивается';
    }

    public function permissions(): array
    {
        return ['report.aging'];
    }

    public function filters(): array
    {
        return ['store'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $stock = $this->stock($s, ['in_stock', 'reserved', 'ordered'])->where('condition', 'new')->groupBy('variant_id');
        $sold = DB::table('sale_lines as sl')->join('documents as d', 'd.id', '=', 'sl.document_id')->join('stock_units as su', 'su.id', '=', 'sl.stock_unit_id')
            ->join('variants as v', 'v.id', '=', 'su.variant_id')->join('models as m', 'm.id', '=', 'v.model_id')->leftJoin('memories as me', 'me.id', '=', 'v.memory_id')
            ->leftJoin('colors as c', 'c.id', '=', 'v.color_id')->where('d.status', 'posted')->where('su.condition', 'new')->whereIn('d.store_id', $s->storeIds ?: [0])
            ->where('d.occurred_at', '>=', now()->subDays(30))->groupBy('v.id', 'm.name_ru', 'me.name', 'c.name_ru')
            ->selectRaw("v.id, concat_ws(' ', m.name_ru, me.name, c.name_ru) as name, count(*) n")->get()->keyBy('id');
        $rows = $sold->map(function ($r) use ($stock) {
            $left = isset($stock[$r->id]) ? $stock[$r->id]->count() : 0;
            $enough = $r->n ? (int) floor($left / ($r->n / 30)) : null;

            return ['variant' => $r->name, 'sold30' => (int) $r->n, 'left' => $left, 'enough' => $enough, 'buy' => max(0, (int) ceil($r->n / 30 * 14) - $left),
                '_class' => $left === 0 ? 'text-bad' : ($enough !== null && $enough < 7 ? 'text-warn' : '')];
        })->filter(fn ($r) => $r['enough'] === null || $r['enough'] < 14)->sortBy('enough')->values();

        return [
            'tiles' => [self::tile(__('Вариантов заканчивается'), $rows->count(), 'int'), self::tile(__('Нет в наличии'), $rows->where('left', 0)->count(), 'int')],
            'columns' => ['variant' => self::col(__('Вариант')), 'sold30' => self::col(__('Продано за 30 дн.'), 'int'), 'left' => self::col(__('Осталось'), 'int'),
                'enough' => self::col(__('Хватит на, дн.'), 'int'), 'buy' => self::col(__('Заказать на 14 дн.'), 'int')],
            'rows' => $rows->all(),
        ];
    }
}
