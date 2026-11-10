<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О18 «Курс доллара и курсовые разницы». */
class FxReport extends Report
{
    public function key(): string
    {
        return 'fx';
    }

    public function code(): string
    {
        return 'О18';
    }

    public function tab(): string
    {
        return 'money';
    }

    public function title(): string
    {
        return 'Курс и курсовые разницы';
    }

    public function permissions(): array
    {
        return ['report.profit'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $org = CurrentOrganization::instance()->requireId();
        $rates = DB::table('exchange_rates')->where('organization_id', $org)->where('currency', 'USD')->where('valid_from', '>=', $p->startUtc())->where('valid_from', '<', $p->endUtc())->orderByDesc('valid_from')->get();
        $names = User::query()->pluck('name', 'id');
        $fx = (int) DB::table('debt_entries')->where('organization_id', $org)->where('occurred_at', '>=', $p->startUtc())->where('occurred_at', '<', $p->endUtc())->sum('fx_diff_kzt');

        return [
            'tiles' => [self::tile(__('Курсовая разница по долгам'), -$fx, 'money', 'profit', __('плюс — заработали на курсе')), self::tile(__('Смен курса'), $rates->count(), 'int')],
            'columns' => ['at' => self::col(__('С какого времени')), 'rate' => self::col(__('Курс $1, ₸')), 'by' => self::col(__('Кто поставил')), 'comment' => self::col(__('Комментарий'))],
            'rows' => $rates->map(fn ($r) => ['at' => Carbon::parse($r->valid_from)->setTimezone($tz)->format('d.m.Y H:i'), 'rate' => str_replace('.', ',', (string) (float) $r->rate), 'by' => $names[$r->set_by] ?? '', 'comment' => $r->comment])->all(),
        ];
    }
}
