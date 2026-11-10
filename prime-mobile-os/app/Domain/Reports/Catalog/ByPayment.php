<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Metrics;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;

/** О5 «Продажи по способам оплаты». */
class ByPayment extends Report
{
    public function key(): string
    {
        return 'payments';
    }

    public function code(): string
    {
        return 'О5';
    }

    public function tab(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Продажи по способам оплаты';
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
        $pay = $this->metrics()->payments($p, $s);
        $sum = max(1, $pay->sum('amount'));
        $notMoney = $pay->only(['tradein', 'bonus', 'exchange_offset', 'prepayment', 'debt'])->sum('amount');
        $rows = $pay->map(fn ($r, $method) => ['method' => Metrics::methodLabel($method), 'count' => $r['count'], 'amount' => $r['amount'],
            'share' => round($r['amount'] * 100 / $sum, 1), 'commission' => $r['commission'], 'commission_pct' => $r['amount'] ? round($r['commission'] * 100 / $r['amount'], 2) : null,
            'net' => $r['amount'] - $r['commission']])->values()->all();

        return [
            'tiles' => [self::tile(__('Деньгами'), $pay->sum('amount') - $notMoney), self::tile(__('Не деньгами (долг, бонусы, зачёт)'), $notMoney), self::tile(__('Комиссии банков'), $pay->sum('commission'), 'money', 'profit')],
            'columns' => ['method' => self::col(__('Способ')), 'count' => self::col(__('Оплат'), 'int'), 'amount' => self::col(__('Сумма'), 'money'), 'share' => self::col(__('Доля, %'), 'pct'),
                'commission' => self::col(__('Комиссия'), 'money', 'profit'), 'commission_pct' => self::col(__('Комиссия, %'), 'pct', 'profit'), 'net' => self::col(__('Пришло на счёт'), 'money', 'profit')],
            'rows' => $rows,
        ];
    }
}
