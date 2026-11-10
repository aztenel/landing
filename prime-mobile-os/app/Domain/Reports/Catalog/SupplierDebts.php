<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О20 «Долги поставщикам». */
class SupplierDebts extends Report
{
    public function key(): string
    {
        return 'supplier_debts';
    }

    public function code(): string
    {
        return 'О20';
    }

    public function tab(): string
    {
        return 'debts';
    }

    public function title(): string
    {
        return 'Долги поставщикам';
    }

    public function permissions(): array
    {
        return ['debt.supplier_view'];
    }

    public function filters(): array
    {
        return [];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $rows = DB::table('counterparty_balances as b')->join('suppliers as sp', 'sp.id', '=', 'b.counterparty_id')->where('b.organization_id', CurrentOrganization::instance()->requireId())
            ->where('b.counterparty_type', 'supplier')->where('b.balance', '!=', 0)->orderBy('b.nearest_due_date')->get(['sp.name', 'b.currency', 'b.balance', 'b.balance_kzt', 'b.nearest_due_date', 'b.avg_rate']);

        return [
            'tiles' => [self::tile(__('Должны в ₸'), (int) $rows->where('currency', 'KZT')->where('balance', '>', 0)->sum('balance')),
                self::tile(__('Должны в $'), '$'.number_format($rows->where('currency', 'USD')->where('balance', '>', 0)->sum('balance') / 100, 0, '', ' '), 'text'),
                self::tile(__('Авансы поставщикам, ₸'), (int) -$rows->where('balance', '<', 0)->sum('balance_kzt'))],
            'columns' => ['name' => self::col(__('Поставщик')), 'currency' => self::col(__('Валюта')), 'debt' => self::col(__('Долг')), 'kzt' => self::col(__('В ₸'), 'money'), 'due' => self::col(__('Ближайший срок'))],
            'rows' => $rows->map(fn ($r) => ['name' => $r->name, 'currency' => $r->currency, 'debt' => $r->currency === 'USD' ? '$'.number_format($r->balance / 100, 2, ',', ' ') : Money::kztMinor((int) $r->balance)->format(),
                'kzt' => (int) $r->balance_kzt, 'due' => $r->nearest_due_date ? Carbon::parse($r->nearest_due_date)->format('d.m.Y') : '—',
                '_class' => $r->nearest_due_date && $r->nearest_due_date <= now()->addDays(3)->toDateString() ? 'text-warn' : ''])->all(),
        ];
    }
}
