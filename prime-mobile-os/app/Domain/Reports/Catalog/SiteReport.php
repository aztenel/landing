<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/** О27 «Сайт: просмотры и клики». */
class SiteReport extends Report
{
    public function key(): string
    {
        return 'site';
    }

    public function code(): string
    {
        return 'О27';
    }

    public function tab(): string
    {
        return 'control';
    }

    public function title(): string
    {
        return 'Сайт: просмотры и клики';
    }

    public function permissions(): array
    {
        return ['site.settings', 'site.visibility'];
    }

    public function filters(): array
    {
        return ['period'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $org = CurrentOrganization::instance()->requireId();
        $views = DB::table('site_stats')->where('organization_id', $org)->where('kind', 'view')->whereBetween('day', [$p->from->toDateString(), $p->to->toDateString()])
            ->groupBy('key')->selectRaw('key, sum(count) n')->pluck('n', 'key');
        $clicks = DB::table('site_leads')->where('organization_id', $org)->where('created_at', '>=', $p->startUtc())->where('created_at', '<', $p->endUtc())
            ->groupBy('public_code')->selectRaw('public_code, count(*) n, count(*) filter (where outcome = \'bought\') b')->get()->keyBy('public_code');
        $titles = DB::table('storefront_listings')->whereIn('public_code', $views->keys()->merge($clicks->keys())->filter())->pluck('title_ru', 'public_code');
        $rows = $views->keys()->merge($clicks->keys())->filter()->unique()->map(fn ($code) => ['code' => $code, 'title' => $titles[$code] ?? '', 'views' => (int) ($views[$code] ?? 0),
            'clicks' => (int) ($clicks[$code]->n ?? 0), 'bought' => (int) ($clicks[$code]->b ?? 0)])->sortByDesc('views')->values();

        return [
            'tiles' => [self::tile(__('Просмотров'), (int) $views->sum(), 'int'), self::tile(__('Нажатий WhatsApp'), (int) $clicks->sum('n'), 'int'), self::tile(__('Купили'), (int) $clicks->sum('b'), 'int')],
            'columns' => ['code' => self::col(__('Код')), 'title' => self::col(__('Товар')), 'views' => self::col(__('Просмотров'), 'int'), 'clicks' => self::col(__('WhatsApp'), 'int'), 'bought' => self::col(__('Купили'), 'int')],
            'rows' => $rows->all(),
        ];
    }
}
