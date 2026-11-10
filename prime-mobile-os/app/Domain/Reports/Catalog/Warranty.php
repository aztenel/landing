<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О26 «Гарантия». */
class Warranty extends Report
{
    public function key(): string
    {
        return 'warranty';
    }

    public function code(): string
    {
        return 'О26';
    }

    public function tab(): string
    {
        return 'control';
    }

    public function title(): string
    {
        return 'Гарантия';
    }

    public function permissions(): array
    {
        return ['report.sales'];
    }

    public function filters(): array
    {
        return ['period', 'store'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $rows = DB::table('warranty_cases as w')->leftJoin('stock_units as su', 'su.id', '=', 'w.stock_unit_id')->where('w.organization_id', CurrentOrganization::instance()->requireId())
            ->whereIn('w.store_id', $s->storeIds ?: [0])->where('w.created_at', '>=', $p->startUtc())->where('w.created_at', '<', $p->endUtc())->orderByDesc('w.created_at')
            ->get(['w.number', 'w.created_at', 'w.status', 'w.complaint', 'w.repair_where', 'su.public_code', 'w.issued_at']);
        $labels = \App\Domain\Warehouse\Services\Warranty::NEXT ?? [];

        return [
            'tiles' => [self::tile(__('Обращений'), $rows->count(), 'int'), self::tile(__('Выдано'), $rows->whereNotNull('issued_at')->count(), 'int')],
            'columns' => ['date' => self::col(__('Принято')), 'number' => self::col(__('№')), 'code' => self::col(__('Телефон')), 'complaint' => self::col(__('Жалоба')), 'status' => self::col(__('Статус'))],
            'rows' => $rows->map(fn ($r) => ['date' => Carbon::parse($r->created_at)->setTimezone($tz)->format('d.m.Y'), 'number' => $r->number, 'code' => $r->public_code,
                'complaint' => $r->complaint, 'status' => $r->status])->all(),
        ];
    }
}
