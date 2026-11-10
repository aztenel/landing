<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О19 «Долги клиентов и просрочка». */
class CustomerDebts extends Report
{
    public function key(): string
    {
        return 'customer_debts';
    }

    public function code(): string
    {
        return 'О19';
    }

    public function tab(): string
    {
        return 'debts';
    }

    public function title(): string
    {
        return 'Долги клиентов';
    }

    public function permissions(): array
    {
        return ['debt.customer_report'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    /** TZ 06 §1.3: a seller sees only «Мои продажи», even with the right to a customer's debt in the cash register. */
    public function allowed(): bool
    {
        return Access::allows('debt.customer_report') && (Access::allows('report.sales') || Access::allows('report.profit'));
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $org = CurrentOrganization::instance()->requireId();
        $rows = DB::table('counterparty_balances as b')->join('customers as c', 'c.id', '=', 'b.counterparty_id')->where('b.organization_id', $org)
            ->where('b.counterparty_type', 'customer')->where('b.balance', '>', 0)->orderBy('b.nearest_due_date')->get(['c.name', 'c.phone', 'b.balance_kzt', 'b.nearest_due_date']);
        $period = fn (string $kind) => (int) DB::table('debt_entries')->where('organization_id', $org)->where('counterparty_type', 'customer')->where('kind', $kind)
            ->where('occurred_at', '>=', $p->startUtc())->where('occurred_at', '<', $p->endUtc())->sum('amount_kzt');
        $today = now()->toDateString();
        $overdue = $rows->filter(fn ($r) => $r->nearest_due_date && $r->nearest_due_date < $today);

        return [
            'tiles' => [self::tile(__('Должны нам всего'), (int) $rows->sum('balance_kzt')), self::tile(__('Просрочено'), (int) $overdue->sum('balance_kzt'), 'money', null, $overdue->count().' '.__('клиентов')),
                self::tile(__('Выдано в долг за период'), $period('sale_credit')), self::tile(__('Погашено за период'), -$period('payment'))],
            'columns' => ['name' => self::col(__('Клиент')), 'phone' => self::col(__('Телефон')), 'debt' => self::col(__('Долг'), 'money'), 'due' => self::col(__('Срок')), 'late' => self::col(__('Просрочка, дн.'), 'int')],
            'rows' => $rows->map(fn ($r) => ['name' => $r->name, 'phone' => $r->phone, 'debt' => (int) $r->balance_kzt, 'due' => $r->nearest_due_date ? Carbon::parse($r->nearest_due_date)->format('d.m.Y') : '—',
                'late' => $r->nearest_due_date && $r->nearest_due_date < $today ? (int) Carbon::parse($r->nearest_due_date)->diffInDays(now(), true) : null,
                '_class' => $r->nearest_due_date && $r->nearest_due_date < $today ? 'text-bad' : ''])->all(),
        ];
    }
}
