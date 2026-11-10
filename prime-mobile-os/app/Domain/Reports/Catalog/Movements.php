<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О14 «Движение товара». */
class Movements extends Report
{
    public function key(): string
    {
        return 'movements';
    }

    public function code(): string
    {
        return 'О14';
    }

    public function tab(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return 'Движение товара';
    }

    public function permissions(): array
    {
        return ['report.sales', 'report.aging'];
    }

    public function filters(): array
    {
        return ['period', 'store'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $rows = DB::table('inventory_movements as im')->join('documents as d', 'd.id', '=', 'im.document_id')->leftJoin('stock_units as su', 'su.id', '=', 'im.stock_unit_id')
            ->leftJoin('accessories as a', 'a.id', '=', 'im.accessory_id')
            ->where('im.organization_id', CurrentOrganization::instance()->requireId())->whereIn('im.store_id', $s->storeIds ?: [0])
            ->where('im.occurred_at', '>=', $p->startUtc())->where('im.occurred_at', '<', $p->endUtc())->orderByDesc('im.id')->limit(5000)
            ->get(['im.occurred_at', 'd.number', 'd.type', 'im.kind', 'im.qty', 'su.public_code', 'a.name_ru', 'im.status_before', 'im.status_after']);
        $types = collect(DocumentType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]);

        return [
            'tiles' => [self::tile(__('Движений'), $rows->count(), 'int')],
            'columns' => ['when' => self::col(__('Когда')), 'doc' => self::col(__('Документ')), 'item' => self::col(__('Товар')), 'qty' => self::col(__('Кол-во'), 'int'), 'status' => self::col(__('Статус'))],
            'rows' => $rows->map(fn ($r) => ['when' => Carbon::parse($r->occurred_at)->setTimezone($tz)->format('d.m.Y H:i'), 'doc' => ($types[$r->type] ?? $r->type).' '.$r->number,
                'item' => $r->public_code ?? $r->name_ru, 'qty' => (int) $r->qty,
                'status' => $r->status_after ? trim(($r->status_before ? UnitStatus::labelOf(UnitStatus::tryFrom($r->status_before)) : '').' → '.UnitStatus::labelOf(UnitStatus::tryFrom($r->status_after)), ' →') : ''])->all(),
        ];
    }
}
