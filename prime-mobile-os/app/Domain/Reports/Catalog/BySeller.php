<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/** О2 «Продажи по продавцам». */
class BySeller extends Report
{
    public function key(): string
    {
        return 'sellers';
    }

    public function code(): string
    {
        return 'О2';
    }

    public function tab(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Продажи по продавцам';
    }

    public function permissions(): array
    {
        return ['report.sales', 'report.profit'];
    }

    public function filters(): array
    {
        return ['period', 'store', 'dir'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $m = $this->metrics();
        $f = $m->facts($p, $s);
        $all = $m->fold($f);
        $tradein = DB::table('documents as d')->join('tradein_acts as a', 'a.document_id', '=', 'd.id')->where('d.status', 'posted')
            ->where('d.organization_id', CurrentOrganization::instance()->requireId())->whereIn('d.store_id', $s->storeIds ?: [0])
            ->where('d.occurred_at', '>=', $p->startUtc())->where('d.occurred_at', '<', $p->endUtc())->groupBy('d.posted_by')
            ->selectRaw('d.posted_by, count(*) n, sum(a.amount) s')->get()->keyBy('posted_by');
        $names = User::query()->pluck('name', 'id');
        $rows = $m->by($f, 'seller_id')->sortByDesc('revenue')->map(fn ($r, $id) => [
            'seller' => $names[$id] ?? '—', 'checks' => $r['checks'], 'revenue' => $r['revenue'], 'share' => $all['revenue'] ? round($r['revenue'] * 100 / $all['revenue'], 1) : null,
            'avg' => $r['avg_check'], 'phones' => $r['phones'], 'accessories' => $r['accessories'], 'attach' => $r['phones'] ? round($r['accessories'] / $r['phones'], 2) : null,
            'discounts' => $r['discounts'], 'returns' => $r['returns'], 'tradein' => (int) ($tradein[$id]->n ?? 0), 'gross' => $r['gross'],
        ])->values()->all();

        return [
            'tiles' => [self::tile(__('Выручка'), $all['revenue']), self::tile(__('Продавцов'), count($rows), 'int')],
            'columns' => ['seller' => self::col(__('Продавец')), 'checks' => self::col(__('Чеков'), 'int'), 'revenue' => self::col(__('Выручка'), 'money'),
                'share' => self::col(__('Доля, %'), 'pct'), 'avg' => self::col(__('Средний чек'), 'money'), 'phones' => self::col(__('Телефонов'), 'int'),
                'accessories' => self::col(__('Аксессуаров'), 'int'), 'attach' => self::col(__('Акс. на 1 телефон'), 'num'), 'discounts' => self::col(__('Скидки'), 'money'),
                'returns' => self::col(__('Возвраты'), 'money'), 'tradein' => self::col(__('Trade-in, актов'), 'int'), 'gross' => self::col(__('Валовая прибыль'), 'money', 'profit')],
            'rows' => $rows,
        ];
    }
}
