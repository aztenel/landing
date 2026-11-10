<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Metrics;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О1 «Продажи и прибыль» (TZ 06 §3). */
class SalesProfit extends Report
{
    public function key(): string
    {
        return 'sales';
    }

    public function code(): string
    {
        return 'О1';
    }

    public function tab(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Продажи и прибыль';
    }

    public function permissions(): array
    {
        return ['report.sales', 'report.profit'];
    }

    public function filters(): array
    {
        return ['period', 'store', 'dir', 'seller', 'cond', 'group'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $m = $this->metrics();
        $f = $m->facts($p, $s);
        $t = $m->totals($p, $s, $f);
        $tz = CurrentOrganization::instance()->require()->timezone;
        $group = in_array($q['group'] ?? '', ['week', 'month', 'store'], true) ? $q['group'] : 'day';
        $rows = $m->by($f, fn ($r) => $group === 'store' ? (string) $r->store_id : Metrics::bucketKey($r->occurred_at, $group, $tz))->sortKeys();
        $stores = DB::table('stores')->pluck('name', 'id');
        $out = $rows->map(function ($r, $k) use ($group, $stores) {
            return ['when' => $group === 'store' ? ($stores[$k] ?? $k) : ($group === 'month' ? $k : Carbon::parse($k)->format('d.m.Y')),
                'checks' => $r['checks'], 'sales' => $r['sales'], 'returns' => $r['returns'], 'revenue' => $r['revenue'], 'cost' => $r['cost'],
                'gross' => $r['gross'], 'margin' => $r['margin'], 'avg' => $r['avg_check']];
        })->values()->all();
        $notes = $t['unknown_cost_count'] ? [__('В периоде :n продаж с неизвестной себестоимостью на :s — прибыль завышена', ['n' => $t['unknown_cost_count'], 's' => Money::kztMinor($t['unknown_cost_amount'])->format()])] : [];
        $pl = [['line' => __('Выручка'), 'sum' => $t['revenue']], ['line' => '− '.__('Себестоимость'), 'sum' => -$t['cost']], ['line' => '= '.__('Валовая прибыль'), 'sum' => $t['gross']],
            ['line' => '− '.__('Комиссии банков'), 'sum' => -$t['commissions']], ['line' => '− '.__('Оплачено бонусами'), 'sum' => -$t['bonus_paid']], ['line' => '= '.__('Прибыль от продаж'), 'sum' => $t['profit_sales']]];
        foreach ($s->lineFiltered() ? [] : $m->expensesByArticle($p, $s) as $article => $sum) {
            $pl[] = ['line' => '− '.$article, 'sum' => -$sum];
        }
        $pl[] = ['line' => '± '.__('Прочее (списания, излишки, касса, курс)'), 'sum' => $t['other']];
        $pl[] = ['line' => '= '.__('Чистая прибыль'), 'sum' => $t['net'], '_class' => 'font-semibold'];

        return [
            'tiles' => [self::tile(__('Выручка'), $t['revenue']), self::tile(__('Валовая прибыль'), $t['gross'], 'money', 'profit', __('маржа').' '.str_replace('.', ',', (string) ($t['margin'] ?? '—')).'%'),
                self::tile(__('Чистая прибыль'), $t['net'], 'money', 'profit'), self::tile(__('Чеков'), $t['checks'], 'int', null, __('средний чек').' '.Money::kztMinor($t['avg_check'])->format()),
                self::tile(__('Штук'), $t['phones'].' / '.$t['accessories'], 'text', null, __('телефоны / аксессуары'))],
            'columns' => ['when' => self::col($group === 'store' ? __('Магазин') : __('Дата')), 'checks' => self::col(__('Чеков'), 'int'), 'sales' => self::col(__('Продажи'), 'money'),
                'returns' => self::col(__('Возвраты'), 'money'), 'revenue' => self::col(__('Выручка'), 'money'), 'cost' => self::col(__('Себестоимость'), 'money', 'profit'),
                'gross' => self::col(__('Валовая прибыль'), 'money', 'profit'), 'margin' => self::col(__('Маржа, %'), 'pct', 'profit'), 'avg' => self::col(__('Средний чек'), 'money')],
            'rows' => $out,
            'total' => ['when' => __('Итого'), 'checks' => $t['checks'], 'sales' => $t['sales'], 'returns' => $t['returns'], 'revenue' => $t['revenue'], 'cost' => $t['cost'], 'gross' => $t['gross'], 'margin' => $t['margin'], 'avg' => $t['avg_check']],
            'notes' => $notes,
            'extra' => [['title' => __('Отчёт о прибылях'), 'sensitive' => 'profit', 'columns' => ['line' => self::col(__('Статья')), 'sum' => self::col(__('Сумма'), 'money')], 'rows' => $pl]],
        ];
    }
}
