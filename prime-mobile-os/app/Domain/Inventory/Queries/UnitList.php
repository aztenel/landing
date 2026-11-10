<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Models\User;
use App\Support\Imei\Imei;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Illuminate\Database\Eloquent\Builder;

/**
 * The phone list of «Склад — Электроника» (TZ 04 Э8), shared by the screen and the Excel export so that both show
 * exactly the same rows. Filters that reveal cost or supplier are ignored without the right.
 *
 * $f: tab (in_stock|preparing|reserved|in_transit|check|sold|all), q, model_id, condition, store_id, supplier_id,
 *     imei (none|stale|ok), age (30|60|90), no_cost (bool).
 */
final class UnitList
{
    public const TABS = [
        'in_stock' => ['В наличии', ['in_stock']],
        'preparing' => ['На подготовке', ['preparing']],
        'reserved' => ['В резерве', ['reserved', 'ordered']],
        'in_transit' => ['В пути', ['in_transit']],
        'check' => ['Брак / на проверке', ['on_check', 'defective']],
        'sold' => ['Проданные', ['sold']],
        'all' => ['Все', null],
    ];

    public static function query(array $f, User $user): Builder
    {
        $statuses = self::TABS[$f['tab'] ?? 'in_stock'][1] ?? null;
        $q = trim((string) ($f['q'] ?? ''));
        $maxAge = CurrentOrganization::instance()->require()->settings()->imeiCheckMaxAgeDays();
        $lastCheck = 'SELECT c.result FROM imei_checks c WHERE c.stock_unit_id = stock_units.id ORDER BY c.checked_at DESC, c.id DESC LIMIT 1';
        $lastCheckAt = 'SELECT c.checked_at FROM imei_checks c WHERE c.stock_unit_id = stock_units.id ORDER BY c.checked_at DESC, c.id DESC LIMIT 1';

        return StockUnit::query()
            ->with(['variant.productModel', 'variant.memory', 'variant.color'])
            ->whereNotNull('status')
            ->when($statuses, fn ($w) => $w->whereIn('status', $statuses))
            ->when(! Access::allows('stock.other_stores'), fn ($w) => $w->where(fn ($s) => $s->where('location_type', '<>', 'store')
                ->orWhereIn('location_id', \App\Support\Ui\WorkingStore::available($user)->pluck('id'))))
            ->when($f['store_id'] ?? null, fn ($w, $id) => $w->where('location_type', 'store')->where('location_id', (int) $id))
            ->when($f['model_id'] ?? null, fn ($w, $id) => $w->whereHas('variant', fn ($v) => $v->where('model_id', (int) $id)))
            ->when($f['condition'] ?? null, fn ($w, $c) => $w->where('condition', $c))
            ->when(($f['supplier_id'] ?? null) && Access::allows('stock.view_supplier'), fn ($w) => $w->where('supplier_id', (int) $f['supplier_id']))
            ->when(($f['no_cost'] ?? false) && Access::allows('stock.view_cost'), fn ($w) => $w->where('cost_unknown', true))
            ->when(($f['imei'] ?? null) === 'none', fn ($w) => $w->whereNotNull('imei1')->whereRaw("coalesce(($lastCheck), '') <> 'registered'"))
            ->when(($f['imei'] ?? null) === 'stale', fn ($w) => $w->whereRaw("($lastCheck) = 'registered' AND ($lastCheckAt) < now() - (? || ' days')::interval", [$maxAge]))
            ->when(($f['imei'] ?? null) === 'ok', fn ($w) => $w->whereRaw("($lastCheck) = 'registered' AND ($lastCheckAt) >= now() - (? || ' days')::interval", [$maxAge]))
            ->when($f['age'] ?? null, fn ($w, $d) => $w->where('received_at', '<', now()->subDays((int) $d)))
            ->when($q !== '', function ($w) use ($q) {
                $digits = preg_replace('/\D/', '', $q);
                $w->where(function ($s) use ($q, $digits) {
                    $s->where('public_code', mb_strtoupper($q));
                    if (strlen($digits) >= 4) {
                        $s->orWhere('imei1', 'like', '%'.$digits)->orWhere('imei2', 'like', '%'.$digits)->orWhere('imei1', 'like', $digits.'%');
                    }
                    $s->orWhere('serial', mb_strtoupper($q))
                        ->orWhereHas('variant.productModel', fn ($m) => $m->where('name_ru', 'ilike', '%'.addcslashes($q, '%_').'%'));
                });
            })
            ->orderByRaw("CASE WHEN status = 'sold' THEN 1 ELSE 0 END")->orderByDesc('received_at')->orderByDesc('id');
    }

    /** Counters of the tabs (one query). @return array<string, int> */
    public static function counts(User $user): array
    {
        $by = self::query(['tab' => 'all'], $user)->reorder()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $out = [];
        foreach (self::TABS as $key => [, $statuses]) {
            $out[$key] = (int) ($statuses === null ? $by->sum() : collect($statuses)->sum(fn ($s) => $by[$s] ?? 0));
        }

        return $out;
    }

    /** «Можно продать?» — reasons in words (TZ 04 Э10.1). @return list<string> */
    public static function sellBlockers(StockUnit $unit): array
    {
        $reasons = [];
        if (! in_array($unit->status, [UnitStatus::InStock, UnitStatus::Reserved, UnitStatus::Ordered], true)) {
            $reasons[] = 'статус «'.UnitStatus::labelOf($unit->status).'»';
        }
        if ($unit->cost_unknown) {
            $reasons[] = 'нет себестоимости';
        }
        $s = CurrentOrganization::instance()->require()->settings();
        $check = $unit->imei1 ? $unit->latestImeiCheck() : null;
        if ($check && in_array($check->result->value, ['not_registered', 'blocked'], true)) {
            $reasons[] = 'госбаза: «'.$check->result->label().'»';
        } elseif ($unit->imei1 && $s->requireImeiCheck() && (! $check || $check->result->value !== 'registered' || $check->checked_at->lt(now()->subDays($s->imeiCheckMaxAgeDays())))) {
            $reasons[] = $check ? 'отметка госбазы устарела' : 'нет отметки госбазы';
        }
        if ($unit->sale_price === null && ! \App\Domain\Catalog\Models\VariantPrice::query()->where('variant_id', $unit->variant_id)->exists()) {
            $reasons[] = 'нет цены';
        }

        return $reasons;
    }

    public static function imeiTail(?string $imei): string
    {
        return $imei ? Imei::format($imei) : '';
    }
}
