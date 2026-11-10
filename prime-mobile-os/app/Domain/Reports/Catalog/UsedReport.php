<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О12 «Отчёт по б/у»: выкуп → подготовка → продажа. */
class UsedReport extends Report
{
    public function key(): string
    {
        return 'used';
    }

    public function code(): string
    {
        return 'О12';
    }

    public function tab(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return 'Отчёт по б/у';
    }

    public function permissions(): array
    {
        return ['report.used'];
    }

    public function filters(): array
    {
        return ['period', 'store'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $acts = DB::table('documents as d')->join('tradein_acts as a', 'a.document_id', '=', 'd.id')->where('d.organization_id', CurrentOrganization::instance()->requireId())
            ->where('d.status', 'posted')->whereIn('d.store_id', $s->storeIds ?: [0])->where('d.occurred_at', '>=', $p->startUtc())->where('d.occurred_at', '<', $p->endUtc());
        $prep = (int) DB::table('documents as d')->join('prep_costs as pc', 'pc.document_id', '=', 'd.id')->where('d.status', 'posted')
            ->where('d.occurred_at', '>=', $p->startUtc())->where('d.occurred_at', '<', $p->endUtc())->sum('pc.amount');
        $f = $this->metrics()->facts($p, new Scope($s->storeIds, condition: 'used'));
        $sold = $f->where('kind', 'sale')->where('sign', 1);
        $units = DB::table('stock_units as su')->join('variants as v', 'v.id', '=', 'su.variant_id')->join('models as m', 'm.id', '=', 'v.model_id')
            ->whereIn('su.id', $sold->pluck('stock_unit_id'))->get(['su.id', 'su.public_code', 'su.grade', 'su.received_at', 'm.name_ru'])->keyBy('id');
        $rows = $sold->map(fn ($r) => ['code' => $units[$r->stock_unit_id]->public_code ?? '', 'model' => $units[$r->stock_unit_id]->name_ru ?? '', 'grade' => $units[$r->stock_unit_id]->grade ?? '',
            'cost' => $r->cost, 'price' => $r->amount, 'profit' => $r->amount - $r->cost, 'markup' => $r->cost ? round(($r->amount - $r->cost) * 100 / $r->cost, 1) : null,
            'days' => isset($units[$r->stock_unit_id]) && $units[$r->stock_unit_id]->received_at ? (int) Carbon::parse($units[$r->stock_unit_id]->received_at)->diffInDays(Carbon::parse($r->occurred_at), true) : null])->values();

        return [
            'tiles' => [self::tile(__('Выкуплено'), (clone $acts)->count().' '.__('шт.'), 'text', null, Money::kztMinor((int) (clone $acts)->sum('a.amount'))->format()),
                self::tile(__('Затраты на подготовку'), $prep), self::tile(__('Продано'), $rows->count(), 'int'),
                self::tile(__('Средняя наценка, %'), $rows->isNotEmpty() ? round($rows->avg('markup'), 1) : null, 'pct', 'profit'),
                self::tile(__('Средний срок продажи, дн.'), $rows->isNotEmpty() ? (int) round($rows->avg('days')) : null, 'int')],
            'columns' => ['code' => self::col(__('Код')), 'model' => self::col(__('Модель')), 'grade' => self::col(__('Грейд')), 'cost' => self::col(__('Себестоимость'), 'money', 'profit'),
                'price' => self::col(__('Продан за'), 'money'), 'profit' => self::col(__('Прибыль'), 'money', 'profit'), 'markup' => self::col(__('Наценка, %'), 'pct', 'profit'), 'days' => self::col(__('Дней до продажи'), 'int')],
            'rows' => $rows->all(),
        ];
    }
}
