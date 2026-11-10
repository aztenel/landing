<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Shared query of phones in stock with model names and retail prices (TZ 06 §2.2). */
trait StockQuery
{
    protected function stock(Scope $s, array $statuses): Collection
    {
        return DB::table('stock_units as su')->join('variants as v', 'v.id', '=', 'su.variant_id')->join('models as m', 'm.id', '=', 'v.model_id')
            ->leftJoin('memories as me', 'me.id', '=', 'v.memory_id')->leftJoin('colors as c', 'c.id', '=', 'v.color_id')
            ->leftJoin('variant_prices as vp', 'vp.variant_id', '=', 'v.id')
            ->where('su.organization_id', CurrentOrganization::instance()->requireId())->whereIn('su.status', $statuses)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('su.location_type', 'store')->whereIn('su.location_id', $s->storeIds ?: [0]))->orWhere('su.location_type', '!=', 'store'))
            ->when($s->condition, fn ($w) => $w->where('su.condition', $s->condition))
            ->get(['su.id', 'su.public_code', 'su.status', 'su.condition', 'su.grade', 'su.battery_percent', 'su.cost_kzt', 'su.cost_unknown', 'su.received_at', 'su.show_on_site',
                'v.id as variant_id', 'v.model_id', 'm.name_ru as model', 'me.name as memory', 'c.name_ru as color', DB::raw('coalesce(su.sale_price, vp.price_kzt) as retail')]);
    }

    protected static function age($receivedAt): ?int
    {
        return $receivedAt ? (int) Carbon::parse($receivedAt)->diffInDays(now(), true) : null;
    }
}
