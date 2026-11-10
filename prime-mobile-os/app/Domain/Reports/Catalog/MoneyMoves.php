<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/** О15 «Движение денег по счетам»: начало + приход − расход = конец (проверка). */
class MoneyMoves extends Report
{
    public function key(): string
    {
        return 'money';
    }

    public function code(): string
    {
        return 'О15';
    }

    public function tab(): string
    {
        return 'money';
    }

    public function title(): string
    {
        return 'Движение денег по счетам';
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
        $org = CurrentOrganization::instance()->requireId();
        $accounts = DB::table('money_accounts')->where('organization_id', $org)->where('type', '!=', 'technical')
            ->where(fn ($w) => $w->whereIn('store_id', $s->storeIds ?: [0])->orWhereNull('store_id'))->orderBy('sort')->get();
        $sum = fn (string $op, string $at) => DB::table('money_entries')->where('organization_id', $org)->where('occurred_at', $op, $at)->groupBy('account_id')->selectRaw('account_id, sum(amount) s')->pluck('s', 'account_id');
        $before = $sum('<', $p->startUtc());
        $in = DB::table('money_entries')->where('organization_id', $org)->where('occurred_at', '>=', $p->startUtc())->where('occurred_at', '<', $p->endUtc())
            ->groupBy('account_id')->selectRaw('account_id, sum(case when amount > 0 then amount else 0 end) inc, sum(case when amount < 0 then -amount else 0 end) outc')->get()->keyBy('account_id');
        $rows = $accounts->map(function ($a) use ($before, $in) {
            $start = (int) ($before[$a->id] ?? 0);
            $inc = (int) ($in[$a->id]->inc ?? 0);
            $out = (int) ($in[$a->id]->outc ?? 0);

            return ['account' => $a->name.($a->currency === 'USD' ? ' ($)' : ''), 'start' => $start, 'in' => $inc, 'out' => $out, 'end' => $start + $inc - $out, '_cur' => $a->currency];
        })->values();
        $kzt = $rows->where('_cur', 'KZT');

        return [
            'tiles' => [self::tile(__('На начало, ₸'), (int) $kzt->sum('start')), self::tile(__('Приход, ₸'), (int) $kzt->sum('in')), self::tile(__('Расход, ₸'), (int) $kzt->sum('out')), self::tile(__('На конец, ₸'), (int) $kzt->sum('end'))],
            'columns' => ['account' => self::col(__('Счёт')), 'start' => self::col(__('На начало'), 'money'), 'in' => self::col(__('Приход'), 'money'), 'out' => self::col(__('Расход'), 'money'), 'end' => self::col(__('На конец'), 'money')],
            'rows' => $rows->map(fn ($r) => array_diff_key($r, ['_cur' => 1]))->all(),
            'notes' => [__('Суммы долларовых счетов — в долларах.')],
        ];
    }
}
