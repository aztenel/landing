<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О28 «Журнал действий» — за период (полный экран — Настройки → Журнал). */
class Audit extends Report
{
    public function key(): string
    {
        return 'audit';
    }

    public function code(): string
    {
        return 'О28';
    }

    public function tab(): string
    {
        return 'control';
    }

    public function title(): string
    {
        return 'Журнал действий';
    }

    public function permissions(): array
    {
        return ['audit.view'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $rows = DB::table('activity_log')->where('organization_id', CurrentOrganization::instance()->requireId())
            ->where('created_at', '>=', $p->from->utc()->toDateTimeString())->where('created_at', '<', $p->to->utc()->addSecond()->toDateTimeString())->orderByDesc('id')->limit(2000)
            ->get(['created_at', 'log_name', 'description', 'causer_id', 'properties']);
        $names = User::query()->pluck('name', 'id');

        return [
            'tiles' => [self::tile(__('Событий'), $rows->count(), 'int')],
            'columns' => ['when' => self::col(__('Когда')), 'who' => self::col(__('Кто')), 'area' => self::col(__('Раздел')), 'what' => self::col(__('Что'))],
            'rows' => $rows->map(fn ($r) => ['when' => Carbon::parse($r->created_at, 'UTC')->setTimezone($tz)->format('d.m.Y H:i'), 'who' => $names[$r->causer_id] ?? __('система'),
                'area' => $r->log_name, 'what' => $r->description])->all(),
        ];
    }
}
