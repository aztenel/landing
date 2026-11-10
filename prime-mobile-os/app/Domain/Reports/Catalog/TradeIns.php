<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О23 «Trade-in». */
class TradeIns extends Report
{
    public function key(): string
    {
        return 'tradein';
    }

    public function code(): string
    {
        return 'О23';
    }

    public function tab(): string
    {
        return 'clients';
    }

    public function title(): string
    {
        return 'Trade-in';
    }

    public function permissions(): array
    {
        return ['report.used', 'report.sales'];
    }

    public function filters(): array
    {
        return ['period', 'store'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $acts = DB::table('documents as d')->join('tradein_acts as a', 'a.document_id', '=', 'd.id')->leftJoin('variants as v', 'v.id', '=', 'a.variant_id')
            ->leftJoin('models as m', 'm.id', '=', 'v.model_id')->leftJoin('stock_units as su', 'su.id', '=', 'a.stock_unit_id')
            ->where('d.organization_id', CurrentOrganization::instance()->requireId())->where('d.status', 'posted')->whereIn('d.store_id', $s->storeIds ?: [0])
            ->where('d.occurred_at', '>=', $p->startUtc())->where('d.occurred_at', '<', $p->endUtc())->orderByDesc('d.occurred_at')
            ->get(['d.number', 'd.occurred_at', 'm.name_ru', 'a.grade', 'a.amount', 'a.grid_price', 'a.offset_amount', 'su.status', 'su.public_code']);
        $n = $acts->count();

        return [
            'tiles' => [self::tile(__('Актов'), $n, 'int'), self::tile(__('Сумма выкупа'), (int) $acts->sum('amount')), self::tile(__('Средняя сумма'), $n ? intdiv((int) $acts->sum('amount'), $n) : 0),
                self::tile(__('Доля зачёта'), $n ? round($acts->filter(fn ($a) => $a->offset_amount > 0)->count() * 100 / $n, 1) : null, 'pct'),
                self::tile(__('Продано из принятых'), $acts->where('status', 'sold')->count(), 'int')],
            'columns' => ['date' => self::col(__('Дата')), 'number' => self::col(__('Акт')), 'model' => self::col(__('Модель')), 'grade' => self::col(__('Грейд')),
                'amount' => self::col(__('Сумма'), 'money'), 'grid' => self::col(__('По сетке'), 'money'), 'offset' => self::col(__('В зачёт'), 'money'), 'code' => self::col(__('Код'))],
            'rows' => $acts->map(fn ($a) => ['date' => Carbon::parse($a->occurred_at)->setTimezone($tz)->format('d.m.Y'), 'number' => $a->number, 'model' => $a->name_ru, 'grade' => $a->grade,
                'amount' => (int) $a->amount, 'grid' => $a->grid_price === null ? null : (int) $a->grid_price, 'offset' => (int) $a->offset_amount, 'code' => $a->public_code,
                '_class' => $a->grid_price !== null && $a->amount > $a->grid_price ? 'text-warn' : ''])->all(),
        ];
    }
}
