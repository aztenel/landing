<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Metrics;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Ui\Access;

/**
 * One report of the catalog О1–О28 (TZ 06 §3). build() returns plain data:
 *   tiles   — [['label', 'value', 'kind' => money|int|pct|text, 'sensitive' => null|profit|cost]]
 *   columns — [key => ['label', 'kind', 'sensitive' => null|profit|cost]]
 *   rows    — list of arrays (key => value; '_url' — drill-down link)
 *   total   — a row or null; notes — yellow plates; extra — more tables [['title', 'columns', 'rows']]
 * Closed columns are removed on the server (§1.3 п. 1): not on the screen, not in Excel.
 */
abstract class Report
{
    public const TABS = ['sales' => 'Продажи', 'stock' => 'Склад', 'money' => 'Деньги', 'debts' => 'Долги', 'clients' => 'Клиенты и бонусы', 'control' => 'Контроль'];

    abstract public function key(): string;

    abstract public function code(): string;      // О1

    abstract public function tab(): string;

    abstract public function title(): string;

    /** Any of these permissions opens the report. */
    abstract public function permissions(): array;

    /** Filters shown in the bar: period, store, dir, seller, cond, model. */
    public function filters(): array
    {
        return ['period', 'store'];
    }

    abstract public function build(Period $p, Scope $s, array $q): array;

    /** Any of the report's permissions — and the user works with reports at all (§1.3: a seller has only «Мои продажи»). */
    public function allowed(): bool
    {
        $reports = collect(['report.sales', 'report.profit', 'report.stock_value', 'report.aging', 'report.used', 'report.approvals', 'audit.view'])->contains(fn ($p) => Access::allows($p));

        return $reports && collect($this->permissions())->contains(fn ($perm) => Access::allows($perm));
    }

    protected function metrics(): Metrics
    {
        return app(Metrics::class);
    }

    protected static function col(string $label, string $kind = 'text', ?string $sensitive = null): array
    {
        return ['label' => $label, 'kind' => $kind, 'sensitive' => $sensitive];
    }

    protected static function tile(string $label, $value, string $kind = 'money', ?string $sensitive = null, ?string $hint = null): array
    {
        return compact('label', 'value', 'kind', 'sensitive', 'hint');
    }

    /** Drop what the user may not see (§1.3): profit — profit.view, cost — stock.view_cost. */
    public static function redact(array $r): array
    {
        $hidden = array_filter(['profit' => ! Access::allows('profit.view'), 'cost' => ! Access::allows('stock.view_cost')]);
        $keep = fn (?string $s) => $s === null || ! isset($hidden[$s]);
        $r['tiles'] = array_values(array_filter($r['tiles'] ?? [], fn ($t) => $keep($t['sensitive'] ?? null)));
        $clean = function (array $cols, array $rows) use ($keep) {
            $cols = array_filter($cols, fn ($c) => $keep($c['sensitive'] ?? null));
            $rows = array_map(fn ($row) => array_intersect_key($row, $cols + ['_url' => 1, '_class' => 1]), $rows);

            return [$cols, $rows];
        };
        [$r['columns'], $r['rows']] = $clean($r['columns'] ?? [], $r['rows'] ?? []);
        if (! empty($r['total'])) {
            $r['total'] = array_intersect_key($r['total'], $r['columns']);
        }
        foreach ($r['extra'] ?? [] as $i => $x) {
            if (isset($x['sensitive']) && ! $keep($x['sensitive'])) {
                unset($r['extra'][$i]);

                continue;
            }
            [$r['extra'][$i]['columns'], $r['extra'][$i]['rows']] = $clean($x['columns'], $x['rows']);
        }
        $r['extra'] = array_values($r['extra'] ?? []);
        $r['notes'] ??= [];

        return $r;
    }
}
