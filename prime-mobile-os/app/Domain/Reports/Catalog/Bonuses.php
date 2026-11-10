<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/** О21 «Бонусы». */
class Bonuses extends Report
{
    public function key(): string
    {
        return 'bonuses';
    }

    public function code(): string
    {
        return 'О21';
    }

    public function tab(): string
    {
        return 'clients';
    }

    public function title(): string
    {
        return 'Бонусы';
    }

    public function permissions(): array
    {
        return ['bonus.settings', 'report.profit'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $org = CurrentOrganization::instance()->requireId();
        $byKind = DB::table('bonus_entries')->where('organization_id', $org)->where('occurred_at', '>=', $p->startUtc())->where('occurred_at', '<', $p->endUtc())
            ->groupBy('kind')->selectRaw('kind, sum(amount) s, count(*) n')->get();
        $bal = DB::table('customer_bonus_balances')->where('organization_id', $org)->selectRaw('sum(available) a, sum(pending) p, count(*) filter (where available > 0) c')->first();
        $labels = ['accrual' => __('Начислено'), 'redeem' => __('Списано в оплату'), 'expire' => __('Сгорело'), 'manual' => __('Вручную'), 'cancel' => __('Отменено (возврат)'), 'return' => __('Возвращено')];

        return [
            'tiles' => [self::tile(__('Доступно у клиентов'), (int) ($bal->a ?? 0), 'money', null, (int) ($bal->c ?? 0).' '.__('клиентов')), self::tile(__('Ждут активации'), (int) ($bal->p ?? 0))],
            'columns' => ['kind' => self::col(__('Операция')), 'count' => self::col(__('Записей'), 'int'), 'sum' => self::col(__('Сумма'), 'money')],
            'rows' => $byKind->map(fn ($r) => ['kind' => $labels[$r->kind] ?? $r->kind, 'count' => (int) $r->n, 'sum' => (int) $r->s])->all(),
        ];
    }
}
