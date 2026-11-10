<?php

namespace App\Domain\Storefront\Services;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Storefront\Models\Listing;
use App\Domain\Storefront\Models\SiteAccessory;
use App\Domain\Storefront\Models\SitePhoto;
use App\Domain\Storefront\Models\SiteStore;
use App\Domain\Tenancy\Models\Store;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ERP → site (TZ 05 §8): rebuild a public card WHOLE from the warehouse (not a delta), so order and repeats of tasks
 * do not matter — the last run is always right. Idempotent. Writes storefront_listings / _accessories / _stores and
 * bumps the page cache version of the firm (§8.4: pages are cached by version; any change drops them).
 */
final class ListingBuilder
{
    /** Rebuild cards of these units; returns how many rows changed. */
    public function units(array $ids): int
    {
        $changed = 0;
        foreach (array_unique(array_filter(array_map('intval', $ids))) as $id) {
            $changed += $this->unit($id) ? 1 : 0;
        }
        if ($changed) {
            self::touch();
        }

        return $changed;
    }

    /** @return bool whether the stored row changed */
    public function unit(int $id): bool
    {
        $unit = StockUnit::query()->with(['variant.productModel.family', 'variant.memory', 'variant.color', 'variant.ram'])->find($id);
        $row = Listing::query()->where('unit_ref', $id)->first();
        if (! $unit) {
            return (bool) $row?->delete();
        }
        if ($unit->status === UnitStatus::Sold) {
            // «Продан» (§9.9): the page stays by its link, without price and buttons; only if it was on the site
            if ($row && $row->public_status !== 'sold') {
                $row->update(['public_status' => 'sold', 'sold_at' => now(), 'updated_at' => now()]);

                return true;
            }

            return false;
        }
        if (Visibility::reasons($unit) !== []) {
            return (bool) $row?->delete();
        }
        $data = $this->card($unit, $row);
        if ($row) {
            $before = $this->comparable($row->getAttributes());
            $row->fill($data);
            if ($this->comparable($row->getAttributes()) == $before) {
                return false;
            }
            $row->updated_at = now();
            $row->save();

            return true;
        }
        Listing::query()->create($data + ['updated_at' => now()]);

        return true;
    }

    private function comparable(array $a): array
    {
        unset($a['updated_at'], $a['id']);
        foreach ($a as $k => $v) {
            $a[$k] = is_array($v) ? json_encode($v) : (is_string($v) && ($j = json_decode($v, true)) !== null && in_array($k, ['photos', 'badges', 'public_checks'], true) ? json_encode($j) : (string) $v);
        }

        return $a;
    }

    /** The public fields of §2.1 — nothing else (no IMEI, cost, supplier, customer). */
    public function card(StockUnit $unit, ?Listing $row = null): array
    {
        $v = $unit->variant;
        $model = $v?->productModel;
        $family = $model?->family;
        $settings = SiteSettings::current();
        $org = CurrentOrganization::instance()->require();
        $used = $unit->condition?->value === 'used';
        $price = intdiv(Visibility::price($unit), 100);
        $compare = $unit->compare_at_price ? intdiv((int) $unit->compare_at_price, 100) : null;
        $compare = $compare && $compare > $price ? $compare : null;
        $colorKk = $v?->color?->name_kk ?: $v?->color?->name_ru;
        $titleRu = trim(implode(' ', array_filter([$model?->name_ru, $v?->memory?->name, $v?->color?->name_ru])));
        $titleKk = trim(implode(' ', array_filter([$model?->name_kk ?: $model?->name_ru, $v?->memory?->name, $colorKk])));
        $installment = $settings->installmentFrom($price);
        $badges = array_values(array_unique(array_merge((array) json_decode((string) $unit->getRawOriginal('site_badges'), true), $compare ? ['sale'] : [])));
        $checks = [];
        if ($used) {
            $list = (array) json_decode((string) DB::table('tradein_acts')->where('stock_unit_id', $unit->id)->latest('id')->value('checklist'), true);
            $checks = array_values(array_filter(array_keys(SiteSettings::PUBLIC_CHECKS), fn ($k) => ($list[$k] ?? null) === 'yes'));
        }
        $check = $unit->imei1 ? $unit->latestImeiCheck() : null;
        $status = $unit->status === UnitStatus::InStock ? 'available' : 'reserved';
        $brand = $family?->brand ?: $family?->name;
        $code = $unit->public_code;
        $mem = $v?->memory;

        return [
            'organization_id' => $org->id,
            'unit_ref' => $unit->id,
            'public_code' => $code,
            'slug' => Str::slug(implode(' ', array_filter([$model?->name_en ?: $model?->name_ru, $mem?->gb ? $mem->gb.'gb' : null, $v?->color?->name_en ?: $v?->color?->name_ru, $code])), '-', 'ru'),
            'state' => $used ? 'used' : 'new',
            'type_code' => $family ? DB::table('product_types')->where('id', $family->product_type_id)->value('code') : null,
            'brand' => $brand,
            'brand_slug' => $brand ? Str::slug($brand, '-', 'ru') : null,
            'model_ref' => $model?->id,
            'model_name' => $model?->name_ru,
            'model_slug' => $model ? Str::slug($model->name_en ?: $model->name_ru, '-', 'ru') : null,
            'variant_ref' => $v?->id,
            'title_ru' => $titleRu,
            'title_kk' => $titleKk,
            'memory_gb' => $mem?->gb,
            'memory' => $mem?->name,
            'ram' => $v?->ram?->name,
            'color_ru' => $v?->color?->name_ru,
            'color_kk' => $colorKk,
            'color_hex' => $v?->color?->hex,
            'grade' => $used ? $unit->grade : null,
            'battery_pct' => $used ? $unit->battery_percent : null,
            'release_year' => $unit->year ?: $model?->release_year,
            'description_ru' => $unit->site_description_ru,
            'description_kk' => $unit->site_description_kk,
            'public_checks' => $checks,
            'imei_verified' => $check?->result?->value === 'registered',
            'price' => $price,
            'compare_at_price' => $compare,
            'badges' => $badges,
            'installment_from' => $installment[0] ?? null,
            'installment_months' => $installment[1] ?? null,
            'warranty_months' => (int) $org->settings()->get($used ? 'warranty.used_months' : 'warranty.new_months'),
            'public_status' => $status,
            'store_ref' => (int) $unit->location_id,
            'photos' => $this->photos($unit),
            'pinned_rank' => $unit->site_pinned_rank && (! $unit->site_pinned_until || Carbon::parse($unit->site_pinned_until)->endOfDay()->isFuture()) ? $unit->site_pinned_rank : null,
            'sort_rank' => ($row?->published_at ?? now())->getTimestamp(),
            'published_at' => $row?->published_at ?? now(),
            'sold_at' => null,
            'group_key' => $used ? 'unit:'.$code : 'variant:'.$v?->id.'|store:'.$unit->location_id.'|price:'.$price,
            'search_text' => Search::normalize(implode(' ', array_filter([$titleRu, $titleKk, $model?->name_en, $brand, $family?->name, $mem?->gb ? $mem->gb.' '.$mem->gb.'gb' : null,
                $v?->color?->name_en, $code, $used ? 'бу б/у used' : 'новый new']))),
        ];
    }

    /** @return list<array<string, string>> public files of the photos, in ERP order; new phones fall back to the model photo (§3.6). */
    public function photos(StockUnit $unit): array
    {
        $own = SitePhoto::query()->where('stock_unit_id', $unit->id)->where('status', 'ready')->whereNull('deleted_at')->orderBy('sort')->orderBy('id')->get();
        $out = $own->map(fn ($p) => $p->files)->all();
        if ($unit->condition?->value !== 'used' && $unit->variant) {
            $model = SitePhoto::query()->where('model_id', $unit->variant->model_id)->whereNull('stock_unit_id')->where('status', 'ready')->whereNull('deleted_at')
                ->orderByRaw('case when color_id = ? then 0 else 1 end', [$unit->variant->color_id])->orderBy('sort')->orderBy('id')->get();
            $sameColor = $model->where('color_id', $unit->variant->color_id);
            $out = array_merge($out, ($sameColor->isNotEmpty() ? $sameColor : $model->take(1))->map(fn ($p) => $p->files)->all());
        }

        return array_values($out);
    }

    /** Units the site shows or may need to show — for the nightly check and «Пересобрать сайт целиком». */
    public function all(): array
    {
        $ids = StockUnit::query()->whereIn('status', array_map(fn ($s) => $s->value, Visibility::SHOWN_STATUSES))->pluck('id')
            ->merge(Listing::query()->pluck('unit_ref'))->unique()->values()->all();
        $changed = [];
        foreach ($ids as $id) {
            if ($this->unit((int) $id)) {
                $changed[] = (int) $id;
            }
        }
        $this->stores();
        $this->accessories();
        self::touch();

        return $changed;
    }

    /** storefront_stores from stores + their site fields in stores.meta (§2.3). */
    public function stores(): void
    {
        $seen = [];
        $stores = Store::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get();
        foreach ($stores as $i => $s) {
            $m = (array) ($s->meta ?? []);
            if (! empty($m['site_hidden'])) {
                continue;
            }
            $seen[] = $s->id;
            SiteStore::query()->updateOrCreate(['store_ref' => $s->id], [
                'slug' => Str::slug($m['site_slug'] ?? $s->name, '-', 'ru') ?: 'store-'.($i + 1),
                'name' => $s->name, 'address_ru' => $s->address, 'address_kk' => $m['address_kk'] ?? null,
                'hours' => $m['hours'] ?? null, 'phone' => $s->phone, 'whatsapp' => $m['whatsapp'] ?? $s->phone,
                'map_url' => $m['map_url'] ?? null, 'city' => $m['city'] ?? null, 'is_main' => $i === 0, 'updated_at' => now(),
            ]);
        }
        SiteStore::query()->whereNotIn('store_ref', $seen ?: [0])->delete();
    }

    /** storefront_accessories (§2.2): availability in words only, never the quantity. */
    public function accessories(): void
    {
        $visibleStores = SiteStore::query()->pluck('store_ref')->all();
        $keep = [];
        foreach (Accessory::query()->where('is_active', true)->where('show_on_site', true)->get() as $a) {
            $price = (int) DB::table('accessory_prices')->where('accessory_id', $a->id)->value('price_kzt');
            $qty = (int) DB::table('accessory_balances')->where('accessory_id', $a->id)->where('location_type', 'store')->whereIn('location_id', $visibleStores ?: [0])->sum('qty');
            $availability = $qty > 0 ? 'in_stock' : ($a->site_can_order ? 'to_order' : null);
            if ($price <= 0 || ! $availability) {
                continue;
            }
            $cat = DB::table('accessory_categories')->where('id', $a->category_id)->first();
            $code = 'PA-'.(2000 + $a->id);
            $keep[] = $a->id;
            SiteAccessory::query()->updateOrCreate(['accessory_ref' => $a->id], [
                'public_code' => $code, 'slug' => Str::slug($a->name_ru.' '.$code, '-', 'ru'),
                'title_ru' => $a->name_ru, 'title_kk' => $a->name_kk ?: $a->name_ru,
                'category_ru' => $cat?->name_ru, 'category_kk' => $cat?->name_kk ?: $cat?->name_ru, 'category_slug' => $cat ? Str::slug($cat->name_ru, '-', 'ru') : null,
                'price' => intdiv($price, 100), 'availability' => $availability,
                'photos' => SitePhoto::query()->where('accessory_id', $a->id)->where('status', 'ready')->whereNull('deleted_at')->orderBy('sort')->get()->map(fn ($p) => $p->files)->all(),
                'search_text' => Search::normalize($a->name_ru.' '.$a->name_kk.' '.$cat?->name_ru.' '.$code), 'updated_at' => now(),
            ]);
        }
        SiteAccessory::query()->whereNotIn('accessory_ref', $keep ?: [0])->delete();
    }

    /** Page cache version of the firm (§8.4): every change bumps it, cached pages of older versions are never read. */
    public static function touch(): void
    {
        $key = 'site:ver:'.CurrentOrganization::instance()->requireId();
        Cache::forever($key, (int) Cache::get($key, 0) + 1);
        Cache::forever('site:synced:'.CurrentOrganization::instance()->requireId(), now()->toIso8601String());
    }

    public static function version(): int
    {
        return (int) Cache::get('site:ver:'.CurrentOrganization::instance()->requireId(), 0);
    }
}
