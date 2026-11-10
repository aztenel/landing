<?php

namespace App\Http\Storefront;

use App\Domain\Storefront\Models\Listing;
use App\Domain\Storefront\Models\SiteAccessory;
use App\Domain\Storefront\Services\Search;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Catalog logic of the site (TZ 05 §5, §9.4): one in-memory pass over the firm's visible cards (hundreds, not
 * millions): plain GET filters (work without JS, the address keeps them), counts next to every value with the other
 * filters applied, folding of identical new phones into one card «в наличии 7».
 */
final class Catalog
{
    public const PER_PAGE = 24;

    /** @return Collection<int, Listing> not sold */
    public static function visible(): Collection
    {
        return Listing::query()->where('public_status', '!=', 'sold')->get();
    }

    /** Identical new phones (same variant, store and price) → one card with stock_count (§9.4, Д5); used never fold. */
    public static function group(Collection $items): Collection
    {
        return $items->groupBy('group_key')->map(function (Collection $g) {
            $available = $g->where('public_status', 'available');
            $first = self::sort($available->isNotEmpty() ? $available : $g, 'pop')->first();
            $first->setAttribute('stock_count', $available->count());
            $first->setAttribute('pinned_any', $g->min('pinned_rank'));

            return $first;
        })->values();
    }

    /** §5.1 «Популярные»: reserved at the end, pinned, hits, newest, cheaper; plus §5.3 sorts. */
    public static function sort(Collection $items, string $sort): Collection
    {
        return $items->sort(function ($a, $b) use ($sort) {
            $r = ($a->public_status === 'reserved') <=> ($b->public_status === 'reserved');
            if ($r) {
                return $r;
            }

            return match ($sort) {
                'cheap' => $a->price <=> $b->price,
                'exp' => $b->price <=> $a->price,
                'new' => $b->published_at <=> $a->published_at,
                'bat' => ($b->battery_pct ?? 0) <=> ($a->battery_pct ?? 0),
                default => [($a->pinned_rank ?? 99), ! in_array('hit', (array) $a->badges, true), -$a->sort_rank, $a->price]
                    <=> [($b->pinned_rank ?? 99), ! in_array('hit', (array) $b->badges, true), -$b->sort_rank, $b->price],
            };
        })->values();
    }

    public static function tiles(Collection $all): array
    {
        $tiles = [];
        foreach (Site::categories() as $slug => [$type, $name, $icon]) {
            $n = self::group($all->where('type_code', $type))->count();
            if ($n) {
                $tiles[] = ['url' => Site::route('catalog', ['category' => $slug]), 'name' => Site::t($name), 'n' => $n, 'icon' => $icon];
            }
        }
        $used = $all->where('state', 'used')->count();
        if ($used) {
            $tiles[] = ['url' => Site::route('used'), 'name' => Site::t('Б/у'), 'n' => $used, 'icon' => 'refresh'];
        }
        $acc = SiteAccessory::query()->count();
        if ($acc) {
            $tiles[] = ['url' => Site::route('catalog', ['category' => 'aksessuary']), 'name' => Site::t('Аксессуары'), 'n' => $acc, 'icon' => 'box'];
        }

        return $tiles;
    }

    /** Filters of the request; the fixed part comes from the address (category, brand, model, used, search). */
    public static function filters(Request $r, array $fixed): array
    {
        $list = fn ($k) => array_values(array_filter(array_map('strval', (array) $r->query($k, [])), fn ($v) => $v !== ''));

        return [
            'state' => $fixed['state'] ?? (in_array($r->query('state'), ['new', 'used'], true) ? $r->query('state') : null),
            'brand' => isset($fixed['brand']) ? [$fixed['brand']] : $list('brand'),
            'model' => isset($fixed['model']) ? [$fixed['model']] : $list('model'),
            'mem' => array_map('intval', $list('mem')),
            'color' => $list('color'),
            'grade' => $list('grade'),
            'bat' => in_array((int) $r->query('bat'), [80, 90], true) ? (int) $r->query('bat') : null,
            'sale' => (bool) $r->query('sale'),
            'pmin' => (int) preg_replace('/\D/', '', (string) $r->query('pmin')) ?: null,
            'pmax' => (int) preg_replace('/\D/', '', (string) $r->query('pmax')) ?: null,
            'store' => array_map('intval', $list('store')),
            'sort' => in_array($r->query('sort'), ['cheap', 'exp', 'new', 'bat'], true) ? $r->query('sort') : 'pop',
        ];
    }

    public static function apply(Collection $items, array $f, ?string $skip = null): Collection
    {
        return $items->filter(function (Listing $l) use ($f, $skip) {
            return ($skip === 'state' || ! $f['state'] || $l->state === $f['state'])
                && ($skip === 'brand' || ! $f['brand'] || in_array($l->brand_slug, $f['brand'], true))
                && ($skip === 'model' || ! $f['model'] || in_array($l->model_slug, $f['model'], true))
                && ($skip === 'mem' || ! $f['mem'] || in_array((int) $l->memory_gb, $f['mem'], true))
                && ($skip === 'color' || ! $f['color'] || in_array((string) $l->color_ru, $f['color'], true))
                && ($skip === 'grade' || ! $f['grade'] || in_array((string) $l->grade, $f['grade'], true))
                && (! $f['bat'] || ($l->battery_pct ?? 0) >= $f['bat'])
                && (! $f['sale'] || $l->compare_at_price > $l->price)
                && (! $f['pmin'] || $l->price >= $f['pmin'])
                && (! $f['pmax'] || $l->price <= $f['pmax'])
                && ($skip === 'store' || ! $f['store'] || in_array((int) $l->store_ref, $f['store'], true));
        });
    }

    /** Everything the catalog view needs. */
    public static function page(Request $r, array $fixed): array
    {
        $base = Listing::query()->where('public_status', '!=', 'sold');
        $category = $fixed['category'] ?? null;
        if ($category) {
            $base->where('type_code', Site::categories()[$category][0]);
        }
        $q = $fixed['q'] ?? null;
        if ($q !== null && $q !== '') {
            Search::apply($base, $q);
        }
        $all = $base->get();
        $f = self::filters($r, $fixed);
        $count = fn (string $facet, string $field) => self::apply($all, $f, $facet)->countBy(fn ($l) => (string) $l->{$field});

        $groups = self::sort(self::group(self::apply($all, $f)), $f['sort']);
        $page = max(1, (int) $r->query('page', 1));
        $paginator = new LengthAwarePaginator($groups->forPage($page, self::PER_PAGE)->values(), $groups->count(), self::PER_PAGE, $page,
            ['path' => '/'.ltrim($r->path(), '/'), 'query' => $r->query()]);

        $title = match (true) {
            isset($fixed['model']) => (string) $all->firstWhere('model_slug', $fixed['model'])?->model_name,
            isset($fixed['q']) => Site::t('Поиск').($q ? ': '.$q : ''),
            ($fixed['state'] ?? null) === 'used' => Site::t('Б/у'),
            (bool) $category => Site::t(Site::categories()[$category][1]).(isset($fixed['brand']) ? ' '.($all->firstWhere('brand_slug', $fixed['brand'])?->brand ?? '') : ''),
            default => Site::t('Каталог'),
        };
        $active = array_filter(['state' => $f['state'] && ! isset($fixed['state']), 'brand' => $f['brand'] && ! isset($fixed['brand']), 'model' => $f['model'] && ! isset($fixed['model']),
            'mem' => (bool) $f['mem'], 'color' => (bool) $f['color'], 'grade' => (bool) $f['grade'], 'bat' => (bool) $f['bat'], 'sale' => $f['sale'],
            'pmin' => (bool) $f['pmin'], 'pmax' => (bool) $f['pmax'], 'store' => (bool) $f['store']]);
        $filtersUsed = count($active) + ($f['sort'] !== 'pop' ? 1 : 0);

        return [
            'title' => trim($title) ?: Site::t('Каталог'), 'fixed' => $fixed, 'f' => $f, 'q' => $q, 'category' => $category,
            'items' => $paginator, 'found' => $groups->count(), 'active' => array_keys($active),
            'facets' => [
                'state' => isset($fixed['state']) ? collect() : $count('state', 'state'),
                'brand' => isset($fixed['brand']) ? collect() : self::apply($all, $f, 'brand')->groupBy('brand_slug')->map(fn ($g) => [$g->first()->brand, $g->count()]),
                'model' => isset($fixed['model']) ? collect() : self::apply($all, $f, 'model')->groupBy('model_slug')->map(fn ($g) => [$g->first()->model_name, $g->count()]),
                'mem' => $count('mem', 'memory_gb')->filter(fn ($n, $k) => $k !== '')->sortKeys(SORT_NUMERIC),
                'color' => self::apply($all, $f, 'color')->filter(fn ($l) => $l->color_ru)->groupBy('color_ru')->map(fn ($g) => [Site::$lang === 'kk' ? $g->first()->color_kk : $g->first()->color_ru, $g->count(), $g->first()->color_hex]),
                'grade' => $count('grade', 'grade')->filter(fn ($n, $k) => $k !== ''),
                'store' => Site::stores()->count() > 1 ? $count('store', 'store_ref') : collect(),
                'sale' => $all->filter(fn ($l) => $l->compare_at_price > $l->price)->count(),
            ],
            // 2+ filters or a sort → noindex, follow; one «brand» or «memory» filter stays indexable (§13.2)
            'noindex' => isset($fixed['q']) || $filtersUsed >= 2 || ($filtersUsed === 1 && ! ($f['mem'] || ($f['brand'] && ! isset($fixed['brand'])))),
            'empty_wa' => trim(($q ?: '').' '.implode(' ', array_filter([$title, $f['mem'] ? implode('/', $f['mem']).' ГБ' : null]))),
        ];
    }

    /** §9.5 п. 10: the same model first, then the same family in price ±15 %; up to 8. */
    public static function similar(Listing $l, Collection $all): Collection
    {
        $pool = self::group($all->where('public_status', 'available')->where('group_key', '!=', $l->group_key));
        $same = self::sort($pool->where('model_ref', $l->model_ref), 'pop');
        $near = self::sort($pool->where('model_ref', '!=', $l->model_ref)->where('brand', $l->brand)
            ->filter(fn ($x) => $x->price >= $l->price * 0.85 && $x->price <= $l->price * 1.15), 'pop');

        return $same->concat($near)->take(8)->values();
    }

    /** §9.6: memory / colour switches of a new phone — only what is really in stock. */
    public static function variants(Listing $l, Collection $all): Collection
    {
        return self::group($all->where('model_ref', $l->model_ref)->where('state', 'new')->where('public_status', 'available'))
            ->sortBy([['memory_gb', 'asc'], ['color_ru', 'asc']])->values();
    }
}
