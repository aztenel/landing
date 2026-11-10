<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/** О22 «Клиенты»: кто сколько купил. */
class Customers extends Report
{
    public function key(): string
    {
        return 'customers';
    }

    public function code(): string
    {
        return 'О22';
    }

    public function tab(): string
    {
        return 'clients';
    }

    public function title(): string
    {
        return 'Клиенты';
    }

    public function permissions(): array
    {
        return ['report.profit'];
    }

    public function filters(): array
    {
        return ['period', 'store'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $m = $this->metrics();
        $f = $m->facts($p, $s)->filter(fn ($r) => $r->customer_id);
        $customers = DB::table('customers')->whereIn('id', $f->pluck('customer_id')->unique())->get(['id', 'name', 'phone', 'source', 'created_at'])->keyBy('id');
        $rows = $m->by($f, 'customer_id')->map(fn ($r, $id) => ['name' => $customers[$id]->name ?? '—', 'phone' => $customers[$id]->phone ?? '', 'source' => $customers[$id]->source ?? '',
            'checks' => $r['checks'], 'revenue' => $r['revenue'], 'gross' => $r['gross']])->sortByDesc('revenue')->values();
        $new = DB::table('customers')->where('organization_id', CurrentOrganization::instance()->requireId())->where('created_at', '>=', $p->startUtc())->where('created_at', '<', $p->endUtc())->count();

        return [
            'tiles' => [self::tile(__('Покупали'), $rows->count(), 'int'), self::tile(__('Новых клиентов'), $new, 'int'), self::tile(__('Выручка с клиентами'), (int) $rows->sum('revenue'))],
            'columns' => ['name' => self::col(__('Клиент')), 'phone' => self::col(__('Телефон')), 'source' => self::col(__('Источник')), 'checks' => self::col(__('Чеков'), 'int'),
                'revenue' => self::col(__('Выручка'), 'money'), 'gross' => self::col(__('Прибыль'), 'money', 'profit')],
            'rows' => $rows->all(),
        ];
    }
}
