<?php

namespace App\Domain\Storefront\Services;

use App\Domain\Catalog\Models\VariantPrice;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Storefront\Models\SitePhoto;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TZ 05 §4.1 «почему не на сайте» — the ONE place the formula lives. Used by the listing builder, the passport tab
 * «Сайт», the ERP section «Сайт → Товары». Empty list = the phone is on the site (the site switch §4.2 «Сайт включён»
 * is shown separately: listings are built anyway so staff can preview).
 */
final class Visibility
{
    public const SHOWN_STATUSES = [UnitStatus::InStock, UnitStatus::Reserved, UnitStatus::Ordered];

    /** @return list<array{code: string, text: string}> */
    public static function reasons(StockUnit $unit): array
    {
        $unit->loadMissing('variant.productModel.family');
        $model = $unit->variant?->productModel;
        $family = $model?->family;
        $r = [];
        $add = function (string $code, string $text) use (&$r) {
            $r[] = ['code' => $code, 'text' => $text];
        };
        if (! in_array($unit->status, self::SHOWN_STATUSES, true)) {
            $add('status', __('Не на сайте: статус «:s»', ['s' => UnitStatus::labelOf($unit->status)]));
        }
        if (! $unit->show_on_site) {
            $who = $unit->site_hidden_by ? User::query()->find($unit->site_hidden_by)?->name : null;
            $add('manual', trim(__('Скрыт вручную').($who ? ' ('.$who.($unit->site_hidden_at ? ', '.Carbon::parse($unit->site_hidden_at)->format('d.m H:i') : '').')' : '')));
        }
        if ($model?->site_hidden) {
            $add('model', __('Скрыта модель :m', ['m' => $model->name_ru]));
        }
        if ($family?->site_hidden) {
            $add('family', __('Скрыт бренд :b', ['b' => trim($family->brand.' '.$family->name)]));
        }
        if ($family && DB::table('product_types')->where('id', $family->product_type_id)->value('site_hidden')) {
            $add('type', __('Скрыта категория :c', ['c' => DB::table('product_types')->where('id', $family->product_type_id)->value('name_ru')]));
        }
        if ($unit->location_type === 'store') {
            $meta = (array) (Store::query()->find($unit->location_id)?->meta ?? []);
            if (! empty($meta['site_hidden'])) {
                $add('store', __('Магазин скрыт с сайта'));
            } elseif ($unit->condition?->value === 'used' && ($meta['site_show_used'] ?? true) === false) {
                $add('store', __('Магазин скрывает б/у'));
            } elseif ($unit->condition?->value !== 'used' && ($meta['site_show_new'] ?? true) === false) {
                $add('store', __('Магазин скрывает новые'));
            }
        }
        if (self::price($unit) <= 0) {
            $add('price', __('Нет цены'));
        }
        if (! self::hasPhoto($unit)) {
            $add('photo', __('Нет фото'));
        }
        if ($unit->cost_unknown) {
            $add('cost', __('Нет себестоимости — спросите владельца'));
        }
        $check = $unit->imei1 ? $unit->latestImeiCheck() : null;
        if ($check && in_array($check->result->value, ['not_registered', 'blocked'], true)) {
            $add('imei', __('Заблокирован в госбазе'));
        }

        return $r;
    }

    /** Retail price, tiyn (unit price, else variant price) — the same price the cash register sells at (§6.1). */
    public static function price(StockUnit $unit): int
    {
        return (int) ($unit->sale_price ?? VariantPrice::query()->where('variant_id', $unit->variant_id)->value('price_kzt') ?? 0);
    }

    public static function hasPhoto(StockUnit $unit): bool
    {
        if (SitePhoto::query()->where('stock_unit_id', $unit->id)->where('status', 'ready')->whereNull('deleted_at')->exists()) {
            return true;
        }

        return $unit->condition?->value !== 'used' && $unit->variant
            && SitePhoto::query()->where('model_id', $unit->variant->model_id)->whereNull('stock_unit_id')->where('status', 'ready')->whereNull('deleted_at')->exists();
    }
}
