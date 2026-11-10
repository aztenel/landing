<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/** О13 «Приходы и поставщики». */
class Purchases extends Report
{
    public function key(): string
    {
        return 'purchases';
    }

    public function code(): string
    {
        return 'О13';
    }

    public function tab(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return 'Приходы и поставщики';
    }

    public function permissions(): array
    {
        return ['report.stock_value', 'report.aging'];
    }

    public function filters(): array
    {
        return ['period', 'store'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $rows = DB::table('documents as d')->join('purchases as pu', 'pu.document_id', '=', 'd.id')->leftJoin('suppliers as sp', 'sp.id', '=', 'pu.supplier_id')
            ->where('d.organization_id', CurrentOrganization::instance()->requireId())->whereIn('d.status', ['posted'])->whereIn('d.store_id', $s->storeIds ?: [0])
            ->where('d.occurred_at', '>=', $p->startUtc())->where('d.occurred_at', '<', $p->endUtc())
            ->groupBy('sp.name')->selectRaw("coalesce(sp.name, '—') supplier, count(*) docs, sum(case when pu.currency = 'KZT' then pu.total else 0 end) total_kzt,
                sum(case when pu.currency = 'USD' then pu.total else 0 end) total_usd, sum(coalesce(pu.credit_amount, 0)) credit")
            ->orderByDesc('total_kzt')->get();
        $units = DB::table('documents as d')->join('purchase_lines as pl', 'pl.document_id', '=', 'd.id')->where('d.status', 'posted')
            ->whereIn('d.store_id', $s->storeIds ?: [0])->where('d.occurred_at', '>=', $p->startUtc())->where('d.occurred_at', '<', $p->endUtc())
            ->selectRaw("sum(case when pl.line_type = 'unit' then 1 else 0 end) phones, sum(case when pl.line_type = 'accessory' then pl.qty else 0 end) acc")->first();

        return [
            'tiles' => [self::tile(__('Приходов'), (int) $rows->sum('docs'), 'int'), self::tile(__('Телефонов / аксессуаров'), (int) ($units->phones ?? 0).' / '.(int) ($units->acc ?? 0), 'text'),
                self::tile(__('Сумма в ₸'), (int) $rows->sum('total_kzt'), 'money', 'cost')],
            'columns' => ['supplier' => self::col(__('Поставщик')), 'docs' => self::col(__('Приходов'), 'int'), 'total_kzt' => self::col(__('Сумма ₸'), 'money', 'cost'),
                'total_usd' => self::col(__('Сумма $'), 'usd', 'cost'), 'credit' => self::col(__('В долг'), 'money', 'cost')],
            'rows' => $rows->map(fn ($r) => (array) $r + [])->map(fn ($r) => array_merge($r, ['docs' => (int) $r['docs'], 'total_kzt' => (int) $r['total_kzt'], 'total_usd' => (int) $r['total_usd'], 'credit' => (int) $r['credit']]))->all(),
        ];
    }
}
