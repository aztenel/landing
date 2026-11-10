<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О24 «Сторно и исправления». */
class Reversals extends Report
{
    public function key(): string
    {
        return 'reversals';
    }

    public function code(): string
    {
        return 'О24';
    }

    public function tab(): string
    {
        return 'control';
    }

    public function title(): string
    {
        return 'Сторно и исправления';
    }

    public function permissions(): array
    {
        return ['report.profit', 'audit.view'];
    }

    public function filters(): array
    {
        return ['period', 'store'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $types = collect(DocumentType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]);
        $rows = DB::table('documents as r')->join('documents as o', 'o.id', '=', 'r.reversal_of_document_id')->where('r.organization_id', CurrentOrganization::instance()->requireId())
            ->where('r.type', 'reversal')->where('r.status', 'posted')->whereIn('o.store_id', $s->storeIds ?: [0])
            ->where('r.occurred_at', '>=', $p->startUtc())->where('r.occurred_at', '<', $p->endUtc())->orderByDesc('r.occurred_at')
            ->get(['r.number', 'r.occurred_at', 'r.posted_by', 'r.reason_code', 'r.reason_text', 'o.number as orig', 'o.type', 'o.occurred_at as orig_at']);
        $names = User::query()->pluck('name', 'id');

        return [
            'tiles' => [self::tile(__('Сторно'), $rows->count(), 'int'), self::tile(__('Из них продаж'), $rows->where('type', 'sale')->count(), 'int')],
            'columns' => ['date' => self::col(__('Когда')), 'number' => self::col(__('Сторно')), 'orig' => self::col(__('Документ')), 'reason' => self::col(__('Причина')),
                'who' => self::col(__('Кто')), 'days' => self::col(__('Дней после документа'), 'int')],
            'rows' => $rows->map(fn ($r) => ['date' => Carbon::parse($r->occurred_at)->setTimezone($tz)->format('d.m.Y H:i'), 'number' => $r->number,
                'orig' => ($types[$r->type] ?? $r->type).' '.$r->orig, 'reason' => trim(($r->reason_code ? ReversalReason::tryFrom($r->reason_code)?->label() : '').' '.$r->reason_text),
                'who' => $names[$r->posted_by] ?? '', 'days' => (int) Carbon::parse($r->orig_at)->diffInDays(Carbon::parse($r->occurred_at), true)])->all(),
        ];
    }
}
