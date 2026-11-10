<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О3 «Продажи по моделям» (модель → вариант → экземпляры). */
class ByModel extends Report
{
    public function key(): string
    {
        return 'models';
    }

    public function code(): string
    {
        return 'О3';
    }

    public function tab(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Продажи по моделям';
    }

    public function permissions(): array
    {
        return ['report.sales', 'report.profit'];
    }

    public function filters(): array
    {
        return ['period', 'store', 'dir', 'cond'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $m = $this->metrics();
        $f = $m->facts($p, $s);
        $units = DB::table('stock_units as su')->join('variants as v', 'v.id', '=', 'su.variant_id')->join('models as mo', 'mo.id', '=', 'v.model_id')
            ->whereIn('su.id', $f->pluck('stock_unit_id')->filter()->unique())->get(['su.id', 'su.variant_id', 'v.model_id', 'mo.name_ru', 'su.received_at'])->keyBy('id');
        $acc = DB::table('accessories')->whereIn('id', $f->pluck('accessory_id')->filter()->unique())->pluck('name_ru', 'id');
        $key = fn ($r) => $r->stock_unit_id ? 'm'.($units[$r->stock_unit_id]->model_id ?? 0) : 'a'.$r->accessory_id;
        $stock = DB::table('stock_units as su')->join('variants as v', 'v.id', '=', 'su.variant_id')->whereIn('su.status', ['in_stock', 'reserved', 'ordered'])
            ->groupBy('v.model_id')->selectRaw('v.model_id, count(*) n')->pluck('n', 'model_id');
        $sold30 = DB::table('sale_lines as sl')->join('documents as d', 'd.id', '=', 'sl.document_id')->join('stock_units as su', 'su.id', '=', 'sl.stock_unit_id')
            ->join('variants as v', 'v.id', '=', 'su.variant_id')->where('d.status', 'posted')->where('d.occurred_at', '>=', now()->subDays(30))
            ->groupBy('v.model_id')->selectRaw('v.model_id, count(*) n')->pluck('n', 'model_id');
        $groups = $f->groupBy($key);
        $rows = $groups->map(function ($g, $k) use ($m, $units, $acc, $stock, $sold30) {
            $r = $m->fold($g);
            $isModel = $k[0] === 'm';
            $id = (int) substr($k, 1);
            $first = $g->first();
            $qty = $isModel ? $r['phones'] : $r['accessories'];
            $days = $isModel ? $g->where('kind', 'sale')->where('sign', 1)->map(fn ($x) => isset($units[$x->stock_unit_id]) && $units[$x->stock_unit_id]->received_at
                ? Carbon::parse($units[$x->stock_unit_id]->received_at)->diffInDays(Carbon::parse($x->occurred_at), true) : null)->filter(fn ($v) => $v !== null) : collect();
            $left = $isModel ? (int) ($stock[$id] ?? 0) : null;
            $speed = $isModel ? (int) ($sold30[$id] ?? 0) : 0;

            return ['name' => $isModel ? ($units[$first->stock_unit_id]->name_ru ?? '—') : ($acc[$id] ?? '—'), 'qty' => $qty, 'revenue' => $r['revenue'],
                'avg' => $qty ? intdiv($r['revenue'], $qty) : null, 'cost' => $r['cost'], 'gross' => $r['gross'], 'margin' => $r['margin'], 'markup' => $r['markup'],
                'days' => $days->isNotEmpty() ? (int) round($days->avg()) : null, 'left' => $left, 'enough' => $isModel && $speed ? (int) round($left / ($speed / 30)) : null];
        })->sortByDesc('gross')->values();
        // ABC by gross profit (A — first 80 %, B — next 15 %, C — the rest)
        $total = max(1, $rows->sum(fn ($r) => max(0, $r['gross'])));
        $acc80 = 0;
        $rows = $rows->map(function ($r) use (&$acc80, $total) {
            $acc80 += max(0, $r['gross']);
            $r['abc'] = $acc80 * 100 / $total <= 80 ? 'A' : ($acc80 * 100 / $total <= 95 ? 'B' : 'C');

            return $r;
        });

        return [
            'tiles' => [self::tile(__('Выручка'), $rows->sum('revenue')), self::tile(__('Позиций'), $rows->count(), 'int')],
            'columns' => ['name' => self::col(__('Модель / аксессуар')), 'qty' => self::col(__('Продано, шт.'), 'int'), 'revenue' => self::col(__('Выручка'), 'money'),
                'avg' => self::col(__('Средняя цена'), 'money'), 'cost' => self::col(__('Себестоимость'), 'money', 'profit'), 'gross' => self::col(__('Валовая прибыль'), 'money', 'profit'),
                'margin' => self::col(__('Маржа, %'), 'pct', 'profit'), 'markup' => self::col(__('Наценка, %'), 'pct', 'profit'), 'abc' => self::col('ABC', 'text', 'profit'),
                'days' => self::col(__('Срок продажи, дн.'), 'int'), 'left' => self::col(__('Остаток, шт.'), 'int'), 'enough' => self::col(__('Хватит на, дн.'), 'int')],
            'rows' => $rows->all(),
        ];
    }
}
