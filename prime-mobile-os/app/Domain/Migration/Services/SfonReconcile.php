<?php

namespace App\Domain\Migration\Services;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Tenancy\Models\Store;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nightly check with sfon during the parallel run (TZ 07 §1.9), 03:00: new phones — count by variant × store;
 * used — one by one by sfon_id; retail prices. Each difference gets a probable cause from our documents of the last
 * 3 days. «Дней без расхождений» — consecutive days with 0 (5 in a row — one of the conditions to switch sfon off).
 */
final class SfonReconcile
{
    public function __construct(private readonly SfonClient $api, private readonly Catalogs $catalogs) {}

    public function run(): array
    {
        $org = CurrentOrganization::instance()->requireId();
        $stores = Store::query()->whereNotNull('sfon_id')->get()->keyBy('sfon_id');
        $diffs = [];
        $theirs = [];
        $usedIds = [];
        foreach ($stores as $sfonId => $store) {
            foreach ($this->api->products(['storeId' => (int) $sfonId]) as $p) {
                $v = $this->catalogs->variant($p);
                if (($p['state'] ?? 'NEW') === 'OLD') {
                    $usedIds[(int) ($p['productId'] ?? 0)] = $p;

                    continue;
                }
                $k = $store->id.'|'.($v?->id ?? 0);
                $theirs[$k] = ['count' => ($theirs[$k]['count'] ?? 0) + (int) ($p['count'] ?? 1), 'price' => isset($p['averagePrice']) ? (int) round((float) $p['averagePrice']) : null, 'title' => $v?->title() ?? ($p['modelName'] ?? '?'), 'store' => $store];
            }
        }
        $ours = StockUnit::query()->where('condition', 'new')->whereIn('status', ['in_stock', 'reserved', 'ordered', 'preparing'])->where('location_type', 'store')
            ->whereIn('location_id', $stores->pluck('id'))->selectRaw('location_id, variant_id, count(*) n')->groupBy('location_id', 'variant_id')->get()
            ->mapWithKeys(fn ($r) => [$r->location_id.'|'.$r->variant_id => (int) $r->n]);
        foreach (collect(array_keys($theirs))->merge($ours->keys())->unique() as $k) {
            [$storeId, $variantId] = array_map('intval', explode('|', $k));
            $a = $theirs[$k]['count'] ?? 0;
            $b = $ours[$k] ?? 0;
            $title = $theirs[$k]['title'] ?? Variant::query()->find($variantId)?->title();
            if ($a !== $b) {
                $diffs[] = ['kind' => 'count', 'text' => __(':t, :s: sfon :a, у нас :b', ['t' => $title, 's' => Store::query()->find($storeId)?->name, 'a' => $a, 'b' => $b]), 'cause' => $this->cause($variantId)];
            }
            $price = $theirs[$k]['price'] ?? null;
            $ourPrice = DB::table('variant_prices')->where('organization_id', $org)->where('variant_id', $variantId)->value('price_kzt');
            if ($price && $ourPrice && Money::kzt($price)->amount !== (int) $ourPrice) {
                $diffs[] = ['kind' => 'price', 'text' => __(':t: цена sfon :a, у нас :b', ['t' => $title, 'a' => Money::kzt($price)->format(), 'b' => Money::kztMinor((int) $ourPrice)->format()]), 'cause' => $this->cause($variantId, true)];
            }
        }
        $ourUsed = StockUnit::query()->whereNotNull('sfon_id')->whereIn('status', ['in_stock', 'reserved', 'ordered', 'preparing'])->pluck('public_code', 'sfon_id');
        foreach (array_diff(array_keys($usedIds), $ourUsed->keys()->all()) as $id) {
            $diffs[] = ['kind' => 'used', 'text' => __('Б/у :m (sfon :id) есть только в sfon', ['m' => $usedIds[$id]['modelName'] ?? '?', 'id' => $id]), 'cause' => __('вероятно, продан у нас и не задублирован в sfon')];
        }
        foreach ($ourUsed as $id => $code) {
            if (! isset($usedIds[$id])) {
                $diffs[] = ['kind' => 'used', 'text' => __('Б/у :c есть только у нас', ['c' => $code]), 'cause' => __('вероятно, продан в sfon и не проведён у нас')];
            }
        }
        $day = now()->toDateString();
        DB::table('sfon_reconciliations')->updateOrInsert(['organization_id' => $org, 'day' => $day], ['discrepancies' => count($diffs), 'details' => json_encode($diffs, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);

        return ['day' => $day, 'diffs' => $diffs, 'streak' => self::streak()];
    }

    /** Our documents of the last 3 days that explain a difference of this variant. */
    private function cause(int $variantId, bool $price = false): string
    {
        if ($price) {
            $ch = DB::table('price_changes')->where('subject_type', 'variant')->where('subject_id', $variantId)->where('created_at', '>=', now()->subDays(3))->latest('id')->first();

            return $ch ? __('смена цены :d у нас — не задублирована в sfon?', ['d' => Carbon::parse($ch->created_at)->format('d.m H:i')]) : __('причина не найдена, проверьте вручную');
        }
        $docs = DB::table('inventory_movements as im')->join('documents as d', 'd.id', '=', 'im.document_id')->join('stock_units as su', 'su.id', '=', 'im.stock_unit_id')
            ->where('su.variant_id', $variantId)->where('im.occurred_at', '>=', now()->subDays(3))->where('d.status', 'posted')->distinct()->pluck('d.number');

        return $docs->isNotEmpty() ? __('наши документы: :d — в sfon нет?', ['d' => $docs->take(3)->implode(', ')]) : __('причина не найдена, проверьте вручную');
    }

    public static function streak(): int
    {
        $n = 0;
        foreach (DB::table('sfon_reconciliations')->where('organization_id', CurrentOrganization::instance()->requireId())->orderByDesc('day')->limit(60)->get() as $r) {
            if ($r->discrepancies > 0) {
                break;
            }
            $n++;
        }

        return $n;
    }
}
