<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О17 «Смены и кассовые расхождения». */
class Shifts extends Report
{
    public function key(): string
    {
        return 'shifts';
    }

    public function code(): string
    {
        return 'О17';
    }

    public function tab(): string
    {
        return 'money';
    }

    public function title(): string
    {
        return 'Смены и расхождения';
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
        $shifts = DB::table('shifts')->where('organization_id', CurrentOrganization::instance()->requireId())->whereIn('store_id', $s->storeIds ?: [0])
            ->where('opened_at', '>=', $p->startUtc())->where('opened_at', '<', $p->endUtc())->orderByDesc('opened_at')->get();
        $diffs = DB::table('shift_counts')->whereIn('shift_id', $shifts->pluck('id'))->groupBy('shift_id', 'phase')->selectRaw('shift_id, phase, sum(diff) d')->get()->groupBy('shift_id');
        $names = User::query()->pluck('name', 'id');
        $rows = $shifts->map(fn ($sh) => ['number' => $sh->number, 'opened' => Carbon::parse($sh->opened_at)->setTimezone($tz)->format('d.m H:i'), 'by' => $names[$sh->opened_by] ?? '',
            'closed' => $sh->closed_at ? Carbon::parse($sh->closed_at)->setTimezone($tz)->format('d.m H:i') : __('открыта'),
            'open_diff' => (int) (($diffs[$sh->id] ?? collect())->firstWhere('phase', 'open')->d ?? 0), 'close_diff' => (int) (($diffs[$sh->id] ?? collect())->firstWhere('phase', 'close')->d ?? 0),
            '_url' => route('erp.print.shift', $sh->id), '_class' => (($diffs[$sh->id] ?? collect())->sum('d') != 0) ? 'text-warn' : ''])->values();

        return [
            'tiles' => [self::tile(__('Смен'), $rows->count(), 'int'), self::tile(__('С расхождением'), $rows->filter(fn ($r) => $r['open_diff'] || $r['close_diff'])->count(), 'int'),
                self::tile(__('Расхождения всего'), (int) $rows->sum(fn ($r) => $r['open_diff'] + $r['close_diff']))],
            'columns' => ['number' => self::col(__('Смена')), 'opened' => self::col(__('Открыта')), 'by' => self::col(__('Кто')), 'closed' => self::col(__('Закрыта')),
                'open_diff' => self::col(__('Расхождение при открытии'), 'money'), 'close_diff' => self::col(__('Расхождение при закрытии'), 'money')],
            'rows' => $rows->all(),
        ];
    }
}
