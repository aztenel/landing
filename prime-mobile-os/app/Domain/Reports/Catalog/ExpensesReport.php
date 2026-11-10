<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;

/** О16 «Расходы по статьям» с сравнением с прошлым периодом. */
class ExpensesReport extends Report
{
    public function key(): string
    {
        return 'expenses';
    }

    public function code(): string
    {
        return 'О16';
    }

    public function tab(): string
    {
        return 'money';
    }

    public function title(): string
    {
        return 'Расходы по статьям';
    }

    public function permissions(): array
    {
        return ['report.profit', 'report.stock_value'];
    }

    public function filters(): array
    {
        return ['period', 'store'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $now = $this->metrics()->expensesByArticle($p, $s);
        $prev = $this->metrics()->expensesByArticle($p->previous(), $s);
        $revenue = $this->metrics()->totals($p, $s)['revenue'];
        $rows = collect(array_keys($now + $prev))->map(fn ($a) => ['article' => $a, 'sum' => $now[$a] ?? 0, 'prev' => $prev[$a] ?? 0,
            'change' => ($prev[$a] ?? 0) ? round((($now[$a] ?? 0) - $prev[$a]) * 100 / $prev[$a], 1) : null, 'share' => $revenue ? round(($now[$a] ?? 0) * 100 / $revenue, 2) : null])->sortByDesc('sum')->values();

        return [
            'tiles' => [self::tile(__('Расходы'), array_sum($now)), self::tile(__('Прошлый период'), array_sum($prev)), self::tile(__('% от выручки'), $revenue ? round(array_sum($now) * 100 / $revenue, 2) : null, 'pct')],
            'columns' => ['article' => self::col(__('Статья')), 'sum' => self::col(__('Сумма'), 'money'), 'prev' => self::col(__('Прошлый период'), 'money'), 'change' => self::col(__('Изменение, %'), 'pct'), 'share' => self::col(__('% от выручки'), 'pct')],
            'rows' => $rows->all(),
        ];
    }
}
