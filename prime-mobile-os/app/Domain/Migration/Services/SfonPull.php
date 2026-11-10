<?php

namespace App\Domain\Migration\Services;

use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Services\OrganizationProvisioner;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * «Забрать из sfon» (TZ 07 Ш1): company and stores, the whole dictionary, everything in stock. Writes our catalogue
 * and stores (with sfon_id) and the raw rows of the stock; the live journals are not touched — that is the ВХО.
 */
final class SfonPull
{
    public function __construct(private readonly SfonClient $api, private readonly Staging $staging, private readonly Catalogs $catalogs) {}

    /** @return array{stores: int, models: int, new_groups: int, new_units: int, used: int, errors: int} */
    public function all(User $user): array
    {
        $run = $this->staging->run('api', 'sfon', $user);
        try {
            $stores = $this->company($run);
            $models = $this->dictionaries($run);
            $stock = $this->stock($run, $stores);
            $totals = ['stores' => count($stores), 'models' => $models] + $stock;
            $this->staging->finish($run, $totals);
            activity('migration')->causedBy($user)->withProperties($totals)->log('sfon.pulled');

            return $totals;
        } catch (UserFacingException $e) {
            $this->staging->finish($run, [], $e->getMessage());
            throw $e;
        }
    }

    /** @return array<int, Store> sfon store id → our store */
    public function company($run): array
    {
        $c = $this->api->company();
        $org = CurrentOrganization::instance()->require();
        $warranty = $c['siteSettingsDto']['Текст гарантийного талона'] ?? null;
        if ($warranty && ! $org->settings()->get('texts.warranty_ru')) {
            $org->settings = array_replace_recursive($org->settings ?? [], ['texts' => ['warranty_ru' => strip_tags((string) $warranty)]]);
            $org->save();
        }
        $ours = Store::query()->where('is_active', true)->orderBy('id')->get();
        $map = [];
        foreach ((array) ($c['stores'] ?? []) as $s) {
            $store = $ours->firstWhere('sfon_id', $s['id'] ?? -1)
                ?? $ours->first(fn ($o) => mb_strtolower(trim($o->name)) === mb_strtolower(trim((string) ($s['storeName'] ?? ''))))
                ?? (count($c['stores']) === 1 && $ours->count() === 1 ? $ours->first() : null);
            if (! $store) {
                $store = Store::query()->create(['name' => (string) ($s['storeName'] ?? 'Магазин'), 'address' => $s['address'] ?? null, 'phone' => $s['telephone'] ?? null, 'is_active' => true]);
                app(OrganizationProvisioner::class)->installStarterAccounts($store);
            }
            $store->forceFill(['sfon_id' => $s['id'] ?? null, 'address' => $store->address ?: ($s['address'] ?? null), 'phone' => $store->phone ?: ($s['telephone'] ?? null),
                'meta' => array_merge((array) $store->meta, array_filter(['whatsapp' => $s['telephoneWhatsapp'] ?? null, 'map_url' => $s['addressLink'] ?? null]),
                    ['site_show_new' => ! ($s['isHideImNew'] ?? false), 'site_show_used' => ! ($s['isHideImOld'] ?? false)])])->save();
            $this->staging->put($run, 'store', (string) ($s['id'] ?? $store->id), $s, ['store_id' => $store->id]);
            $map[(int) ($s['id'] ?? 0)] = $store;
        }

        return $map;
    }

    public function dictionaries($run): int
    {
        $models = 0;
        foreach ($this->api->dictionary('memories') as $m) {
            $this->catalogs->memory(Catalogs::name($m, 'ru') ?? ($m['code'] ?? null), $m['id'] ?? null);
        }
        foreach ($this->api->dictionary('colors') as $c) {
            $this->catalogs->color(Catalogs::name($c, 'ru'), Catalogs::name($c, 'kz'), Catalogs::name($c, 'en'), $c['id'] ?? null);
        }
        foreach ($this->api->dictionary('rams') as $r) {
            $this->catalogs->ram(Catalogs::name($r, 'ru') ?? ($r['code'] ?? null), $r['id'] ?? null);
        }
        foreach ($this->api->dictionary('product-types') as $t) {
            $type = $this->catalogs->type($t);
            foreach ($this->api->dictionary('product-types/'.($t['id'] ?? 0).'/families') as $f) {
                $family = $this->catalogs->family($f, $type);
                foreach ($this->api->dictionary('families/'.($f['id'] ?? 0).'/models') as $m) {
                    $this->catalogs->model($m, $family);
                    $models++;
                }
            }
        }

        return $models;
    }

    /** New phones by groups (count, average price), used one by one with the card (year, battery, description, photos). */
    public function stock($run, array $stores): array
    {
        $t = ['new_groups' => 0, 'new_units' => 0, 'used' => 0, 'errors' => 0];
        $seen = [];
        foreach ($stores as $sfonStore => $store) {
            foreach ($this->api->products(['storeId' => $sfonStore]) as $p) {
                $variant = $this->catalogs->variant($p);
                $err = $variant ? null : __('нет варианта: :m :mem :c', ['m' => $p['modelName'] ?? '?', 'mem' => $p['memoryName'] ?? '', 'c' => $p['colorName'] ?? '']);
                $price = isset($p['averagePrice']) ? Money::kzt((int) round((float) $p['averagePrice']))->amount : null;
                if (($p['state'] ?? 'NEW') === 'OLD' && ! empty($p['productId'])) {
                    $detail = [];
                    try {
                        $detail = (array) ($this->api->used((int) $p['productId'])['productInfo'] ?? []);
                    } catch (UserFacingException) {
                    }
                    $info = $detail + $p;
                    $key = 'old|'.$p['productId'];
                    $this->staging->put($run, 'stock_used', $key, $info, ['variant_id' => $variant?->id, 'store_id' => $store->id, 'price' => $price,
                        'year' => $info['year'] ?? null, 'battery' => $info['batteryState'] ?? null, 'description' => $info['description'] ?? null,
                        'photos' => array_values(array_filter(array_map(fn ($ph) => $ph['url'] ?? null, (array) ($info['photos'] ?? []))))], $err);
                    $t['used']++;
                } else {
                    $key = 'new|'.$sfonStore.'|'.($p['modelId'] ?? $p['modelName'] ?? '').'|'.($p['memoryCode'] ?? $p['memoryName'] ?? '').'|'.($p['colorCode'] ?? $p['colorName'] ?? '').'|'.($p['ramCode'] ?? '');
                    $this->staging->put($run, 'stock_new', $key, $p, ['variant_id' => $variant?->id, 'store_id' => $store->id, 'count' => (int) ($p['count'] ?? 1), 'price' => $price,
                        'old_price' => isset($p['oldPrice']) ? Money::kzt((int) round((float) $p['oldPrice']))->amount : null], $err);
                    $t['new_groups']++;
                    $t['new_units'] += (int) ($p['count'] ?? 1);
                }
                $seen[] = $key;
                $t['errors'] += $err ? 1 : 0;
            }
        }
        // what is no longer in sfon (sold there before day X) leaves the draft
        DB::table('import_rows')->where('organization_id', CurrentOrganization::instance()->requireId())->whereIn('kind', ['stock_new', 'stock_used'])
            ->whereNotIn('sfon_key', $seen ?: ['-'])->where('status', '!=', 'in_vxo')->update(['status' => 'skipped', 'error' => __('больше нет в наличии в sfon'), 'updated_at' => now()]);

        return $t;
    }
}
