<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/** О4 «Продажи по источникам клиентов». */
class BySource extends Report
{
    public function key(): string
    {
        return 'sources';
    }

    public function code(): string
    {
        return 'О4';
    }

    public function tab(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Продажи по источникам';
    }

    public function permissions(): array
    {
        return ['report.sales'];
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
        $firstBuy = DB::table('sales as sa')->join('documents as d', 'd.id', '=', 'sa.document_id')->where('d.status', 'posted')->whereNotNull('sa.customer_id')
            ->groupBy('sa.customer_id')->selectRaw('sa.customer_id, min(d.occurred_at) first')->pluck('first', 'customer_id');
        $rows = $f->groupBy(fn ($r) => $r->source ?: '—')->map(function ($g, $src) use ($m, $firstBuy, $p) {
            $r = $m->fold($g);
            $new = $g->pluck('customer_id')->filter()->unique()->filter(fn ($c) => isset($firstBuy[$c]) && $firstBuy[$c] >= $p->startUtc() && $firstBuy[$c] < $p->endUtc())->count();

            return ['source' => $src === '—' ? __('Не указан') : $src, 'checks' => $r['checks'], 'revenue' => $r['revenue'], 'avg' => $r['avg_check'], 'new' => $new, 'gross' => $r['gross']];
        })->sortByDesc('revenue')->values();
        $none = $rows->firstWhere('source', __('Не указан'));
        $clicks = DB::table('site_leads')->where('organization_id', CurrentOrganization::instance()->requireId())
            ->where('created_at', '>=', $p->startUtc())->where('created_at', '<', $p->endUtc())->count();

        return [
            'tiles' => [self::tile(__('Выручка'), $all['revenue']), self::tile(__('Клики WhatsApp с сайта'), $clicks, 'int')],
            'columns' => ['source' => self::col(__('Источник')), 'checks' => self::col(__('Чеков'), 'int'), 'revenue' => self::col(__('Выручка'), 'money'),
                'avg' => self::col(__('Средний чек'), 'money'), 'new' => self::col(__('Новых клиентов'), 'int'), 'gross' => self::col(__('Валовая прибыль'), 'money', 'profit')],
            'rows' => $rows->all(),
            'notes' => $none && $all['revenue'] && $none['revenue'] * 100 / $all['revenue'] > 20 ? [__('Продавцы часто не указывают источник')] : [],
        ];
    }
}
