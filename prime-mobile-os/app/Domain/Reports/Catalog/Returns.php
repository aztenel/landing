<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О6 «Возвраты». */
class Returns extends Report
{
    public function key(): string
    {
        return 'returns';
    }

    public function code(): string
    {
        return 'О6';
    }

    public function tab(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Возвраты';
    }

    public function permissions(): array
    {
        return ['report.sales'];
    }

    public function filters(): array
    {
        return ['period', 'store', 'seller'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $f = $this->metrics()->facts($p, $s);
        $ret = $f->where('kind', 'return')->where('sign', 1);
        $saleDocs = DB::table('documents')->whereIn('id', $ret->pluck('sale_doc'))->get(['id', 'number', 'occurred_at'])->keyBy('id');
        $units = DB::table('stock_units')->whereIn('id', $ret->pluck('stock_unit_id')->filter())->pluck('public_code', 'id');
        $acc = DB::table('accessories')->whereIn('id', $ret->pluck('accessory_id')->filter())->pluck('name_ru', 'id');
        $sales = $f->where('kind', 'sale')->sum(fn ($r) => $r->sign * $r->amount);
        $rows = $ret->map(fn ($r) => ['date' => Carbon::parse($r->occurred_at)->setTimezone(CurrentOrganization::instance()->require()->timezone)->format('d.m.Y'),
            'check' => $saleDocs[$r->sale_doc]->number ?? '', 'item' => $r->stock_unit_id ? ($units[$r->stock_unit_id] ?? '') : ($acc[$r->accessory_id] ?? ''), 'qty' => $r->qty,
            'amount' => $r->amount, 'days' => isset($saleDocs[$r->sale_doc]) ? (int) Carbon::parse($saleDocs[$r->sale_doc]->occurred_at)->diffInDays(Carbon::parse($r->occurred_at), true) : null])->values();

        return [
            'tiles' => [self::tile(__('Возвратов'), $rows->count(), 'int'), self::tile(__('На сумму'), (int) $rows->sum('amount')),
                self::tile(__('% от продаж'), $sales ? round($rows->sum('amount') * 100 / $sales, 2) : null, 'pct'), self::tile(__('Средний срок до возврата, дн.'), $rows->isNotEmpty() ? (int) round($rows->avg('days')) : null, 'int')],
            'columns' => ['date' => self::col(__('Дата возврата')), 'check' => self::col(__('Исходный чек')), 'item' => self::col(__('Товар')), 'qty' => self::col(__('Шт.'), 'int'),
                'amount' => self::col(__('Сумма'), 'money'), 'days' => self::col(__('Дней от продажи'), 'int')],
            'rows' => $rows->all(),
        ];
    }
}
