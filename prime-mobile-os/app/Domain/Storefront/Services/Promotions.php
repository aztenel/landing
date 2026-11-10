<?php

namespace App\Domain\Storefront\Services;

use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Services\PriceService;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Storefront\Support\SiteChange;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Акции (TZ 05 §6.3). A promotion never bypasses «цена на сайте = цена в кассе»: at the start it posts price changes
 * (reason «promo:N») for every matching phone; at the end it puts back the price before the promotion — unless the
 * price was changed by hand during it: such phones go to «Решите сами» for the owner.
 */
final class Promotions
{
    /** Phones in stock that match the filter. */
    public function units(object $promo): Collection
    {
        $f = (array) (is_string($promo->filter) ? json_decode($promo->filter, true) : $promo->filter);

        return StockUnit::query()->with('variant.productModel.family')->whereIn('status', ['in_stock', 'reserved', 'ordered'])
            ->when($f['condition'] ?? null, fn ($q, $c) => $q->where('condition', $c))
            ->when($f['model_ids'] ?? null, fn ($q, $ids) => $q->whereIn('variant_id', DB::table('variants')->whereIn('model_id', $ids)->select('id')))
            ->when($f['type_code'] ?? null, fn ($q, $code) => $q->whereIn('variant_id', DB::table('variants as v')->join('models as m', 'm.id', '=', 'v.model_id')
                ->join('families as fa', 'fa.id', '=', 'm.family_id')->join('product_types as t', 't.id', '=', 'fa.product_type_id')->where('t.code', $code)->select('v.id')))
            ->when($f['codes'] ?? null, fn ($q, $codes) => $q->whereIn('public_code', $codes))
            ->get();
    }

    /** New price, tiyn: −% / −₸ / fixed, rounded DOWN to the step (§6.3: 489 000 −5% → 464 550 → 464 000). */
    public static function price(int $before, string $type, float $value, int $rounding): int
    {
        $tenge = intdiv($before, 100);
        $new = match ($type) {
            'percent' => $tenge * (100 - $value) / 100,
            'amount' => $tenge - $value,
            default => $value,
        };
        $step = max(1, $rounding);

        return max(0, (int) (floor($new / $step) * $step)) * 100;
    }

    /** «Предпросмотр»: code, was, will be, cost and margin (by right), warning below cost. */
    public function preview(object $promo): Collection
    {
        return $this->units($promo)->map(function (StockUnit $u) use ($promo) {
            $before = Visibility::price($u);
            $new = self::price($before, $promo->discount_type, (float) $promo->discount_value, (int) $promo->rounding);

            return ['unit' => $u, 'before' => $before, 'new' => $new, 'cost' => $u->cost_unknown ? null : (int) $u->cost_kzt,
                'below' => ! $u->cost_unknown && $new < (int) $u->cost_kzt];
        })->filter(fn ($r) => $r['before'] > 0 && $r['new'] < $r['before'])->values();
    }

    public function start(int $id): void
    {
        $promo = DB::table('promotions')->where('id', $id)->first();
        $author = User::query()->findOrFail($promo->created_by);
        foreach ($this->preview($promo) as $r) {
            $u = $r['unit'];
            DB::transaction(function () use ($u, $r, $promo, $author) {
                DB::table('promotion_items')->insertOrIgnore(['organization_id' => $promo->organization_id, 'promotion_id' => $promo->id, 'stock_unit_id' => $u->id,
                    'price_before' => $u->sale_price, 'compare_before' => $u->compare_at_price, 'price_promo' => $r['new']]);
                app(PriceService::class)->setUnitPrice($u, Money::kztMinor($r['new']), $author, 'promo:'.$promo->id, (bool) $promo->show_old);
            });
        }
        DB::table('promotions')->where('id', $id)->update(['status' => 'active', 'started_at' => now(), 'updated_at' => now()]);
        activity('site')->causedBy($author)->withProperties(['promotion' => $promo->name_ru])->log('promotion.started');
    }

    public function finish(int $id): void
    {
        $promo = DB::table('promotions')->where('id', $id)->first();
        $author = User::query()->findOrFail($promo->created_by);
        $manual = [];
        foreach (DB::table('promotion_items')->where('promotion_id', $id)->whereNull('result')->get() as $item) {
            $u = StockUnit::query()->find($item->stock_unit_id);
            if (! $u || ! in_array($u->status?->value, ['in_stock', 'reserved', 'ordered'], true)) {
                DB::table('promotion_items')->where('id', $item->id)->update(['result' => 'sold']);

                continue;
            }
            if ((int) $u->sale_price !== (int) $item->price_promo) {
                $manual[] = $u->public_code;
                DB::table('promotion_items')->where('id', $item->id)->update(['result' => 'manual']);

                continue;
            }
            DB::transaction(function () use ($u, $item, $author, $id) {
                $back = $item->price_before ?? DB::table('variant_prices')->where('variant_id', $u->variant_id)->value('price_kzt');
                app(PriceService::class)->setUnitPrice($u, Money::kztMinor((int) $back), $author, 'promo-end:'.$id);
                $u->refresh()->forceFill(['compare_at_price' => $item->compare_before, 'sale_price' => $item->price_before])->save();
                SiteChange::units([$u->id], 'promo end');
                DB::table('promotion_items')->where('id', $item->id)->update(['result' => 'restored']);
            });
        }
        DB::table('promotions')->where('id', $id)->update(['status' => 'finished', 'finished_at' => now(), 'updated_at' => now()]);
        if ($manual) {
            app(Notifier::class)->toOwners(NotificationType::System, __('Акция «:n» закончилась: решите сами', ['n' => $promo->name_ru]),
                __('Цену меняли вручную во время акции, прежняя не возвращена: :c', ['c' => implode(', ', $manual)]));
        }
        activity('site')->causedBy($author)->withProperties(['promotion' => $promo->name_ru, 'manual' => $manual])->log('promotion.finished');
    }

    /** Every minute: start the planned ones, finish the expired ones. */
    public function tick(): void
    {
        foreach (DB::table('promotions')->where('organization_id', CurrentOrganization::instance()->requireId())->where('status', 'planned')->where('starts_at', '<=', now())->pluck('id') as $id) {
            $this->start((int) $id);
        }
        foreach (DB::table('promotions')->where('organization_id', CurrentOrganization::instance()->requireId())->where('status', 'active')->where('ends_at', '<=', now())->pluck('id') as $id) {
            $this->finish((int) $id);
        }
    }
}
