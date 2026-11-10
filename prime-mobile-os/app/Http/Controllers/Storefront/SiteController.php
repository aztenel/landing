<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Storefront\Models\Banner;
use App\Domain\Storefront\Models\Listing;
use App\Domain\Storefront\Models\SiteAccessory;
use App\Domain\Storefront\Models\SitePage;
use App\Domain\Storefront\Services\ListingBuilder;
use App\Http\Storefront\Catalog;
use App\Http\Storefront\Site;
use App\Http\Storefront\StorefrontContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Pages of the public site (TZ 05 §9). Read-only on storefront_* (+ counters in site_stats). Pages are cached as ready
 * HTML by the firm's cache version (§8.4): any rebuild of a card bumps the version.
 */
class SiteController
{
    public function home()
    {
        return $this->cached(60, function () {
            $all = Catalog::visible();
            $groups = Catalog::group($all);

            return view('storefront.home', [
                'banners' => Banner::query()->where('is_active', true)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->orderBy('sort')->limit(6)->get()
                    ->filter(fn ($b) => ! $b->price_model_ref || $all->where('model_ref', $b->price_model_ref)->where('public_status', 'available')->isNotEmpty())
                    ->map(fn ($b) => $b->setAttribute('price_from', $b->price_model_ref ? $all->where('model_ref', $b->price_model_ref)->min('price') : null))->values(),
                'tiles' => Catalog::tiles($all),
                'newest' => Catalog::sort($groups->where('state', 'new'), 'new')->take(12),
                'used' => Catalog::sort($groups->where('state', 'used'), 'pop')->take(12),
                'hits' => Catalog::sort($groups->filter(fn ($l) => in_array('hit', (array) $l->badges, true)), 'pop')->take(12),
            ]);
        });
    }

    public function catalog(Request $request, ?string $category = null, ?string $brand = null)
    {
        if ($category === 'aksessuary') {
            return $this->accessories($request);
        }
        abort_if($category && ! isset(Site::categories()[$category]), 404);

        return $this->cached(60, fn () => view('storefront.catalog', Catalog::page($request, ['category' => $category, 'brand' => $brand])));
    }

    public function used(Request $request)
    {
        return $this->cached(60, fn () => view('storefront.catalog', Catalog::page($request, ['state' => 'used'])));
    }

    public function model(Request $request, string $slug)
    {
        abort_unless(Listing::query()->where('model_slug', $slug)->exists(), 404);

        return $this->cached(60, fn () => view('storefront.catalog', Catalog::page($request, ['model' => $slug])));
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q'));
        if (preg_match('/^\s*(PM|РМ)[-\s]?(\d{3,})\s*$/iu', $q, $m)) {
            $code = 'PM-'.$m[2];
            if (Listing::query()->where('public_code', $code)->exists()) {
                return redirect(Site::route('phone', ['code' => $code]));
            }
        }
        if (preg_match('/^\s*PA[-\s]?(\d{3,})\s*$/i', $q, $m) && ($a = SiteAccessory::query()->where('public_code', 'PA-'.$m[1])->first())) {
            return redirect(Site::route('item', ['code' => $a->public_code]));
        }
        $data = Catalog::page($request, ['q' => $q]);
        if ($q !== '' && $data['found'] === 0) {
            $this->stat('miss', mb_substr(mb_strtolower($q), 0, 120));
        }

        return response()->view('storefront.catalog', $data)->header('X-Robots-Tag', 'noindex');
    }

    public function phone(string $code)
    {
        preg_match('/^(PM-\d+)/i', $code, $m);
        $listing = $m ? Listing::query()->where('public_code', mb_strtoupper($m[1]))->first() : null;
        abort_unless($listing, 404);
        $this->stat('view', $listing->public_code);
        if ($listing->public_status === 'sold' && $listing->sold_at && $listing->sold_at->lt(now()->subDays(180)) && $listing->model_slug) {
            return redirect(Site::route('model', ['slug' => $listing->model_slug]), 301);
        }

        return $this->cached(600, function () use ($listing) {
            $all = Catalog::visible();
            $same = $all->where('group_key', $listing->group_key)->where('public_status', 'available');

            return view('storefront.phone', [
                'l' => $listing,
                'store' => Site::store($listing->store_ref),
                'inStock' => $listing->state === 'new' ? $same->count() : null,
                'similar' => Catalog::similar($listing, $all),
                'variants' => $listing->state === 'new' ? Catalog::variants($listing, $all) : collect(),
                'terms' => Site::settings()->installmentTerms($listing->price),
            ]);
        }, $listing->public_status === 'sold' && $listing->sold_at?->lt(now()->subDays(30)) ? ['X-Robots-Tag' => 'noindex'] : []);
    }

    public function short(string $code)
    {
        return redirect(Site::route('phone', ['code' => mb_strtoupper($code)]), 301);
    }

    public function item(string $code)
    {
        preg_match('/^(PA-\d+)/i', $code, $m);
        $a = $m ? SiteAccessory::query()->where('public_code', mb_strtoupper($m[1]))->first() : null;
        abort_unless($a, 404);
        $this->stat('view', $a->public_code);

        return $this->cached(600, fn () => view('storefront.item', ['a' => $a, 'store' => Site::store()]));
    }

    public function accessories(Request $request)
    {
        $q = SiteAccessory::query()->orderBy('category_ru')->orderBy('title_ru');
        if ($cat = $request->query('cat')) {
            $q->where('category_slug', $cat);
        }

        return $this->cached(60, fn () => view('storefront.accessories', [
            'items' => $q->paginate(24)->withQueryString(), 'cats' => SiteAccessory::query()->select('category_slug', 'category_ru', 'category_kk', DB::raw('count(*) as n'))
                ->groupBy('category_slug', 'category_ru', 'category_kk')->orderBy('category_ru')->get(), 'cat' => $cat,
        ]));
    }

    public function store()
    {
        return $this->cached(3600, fn () => view('storefront.store', ['stores' => Site::stores()]));
    }

    public function tradein(Request $request)
    {
        return view('storefront.tradein', [
            'models' => Listing::query()->distinct()->orderBy('model_name')->pluck('model_name')->filter()->values(),
            'want' => preg_match('/^PM-\d+$/', (string) $request->query('want')) ? $request->query('want') : null,
            'page' => $this->pageText('trade-in'),
        ]);
    }

    public function page(string $slug)
    {
        $titles = ['rassrochka' => 'Рассрочка', 'garantiya' => 'Гарантия, обмен и возврат', 'privacy' => 'Политика конфиденциальности', 'about' => 'О магазине'];
        abort_unless(isset($titles[$slug]), 404);
        if ($slug === 'rassrochka' && ! Site::settings()->get('installment.enabled')) {
            abort(404);
        }

        return $this->cached(3600, fn () => view('storefront.page', ['slug' => $slug, 'title' => Site::t($titles[$slug]), 'page' => $this->pageText($slug)]));
    }

    public function sitemap()
    {
        $base = rtrim(request()->getSchemeAndHttpHost(), '/');
        $urls = collect(['/', '/catalog', '/bu', '/store', '/trade-in', '/garantiya', '/privacy'])
            ->merge(Site::settings()->get('installment.enabled') ? ['/rassrochka'] : [])
            ->merge(collect(Site::categories())->keys()->map(fn ($c) => '/catalog/'.$c))
            ->merge(Listing::query()->whereNotNull('model_slug')->distinct()->pluck('model_slug')->map(fn ($s) => '/model/'.$s))
            ->merge(Listing::query()->where(fn ($q) => $q->where('public_status', '!=', 'sold')->orWhere('sold_at', '>=', now()->subDays(30)))->get(['public_code', 'slug'])
                ->map(fn ($l) => '/phone/'.$l->public_code.'-'.preg_replace('/-'.strtolower($l->public_code).'$/', '', $l->slug)))
            ->merge(SiteAccessory::query()->pluck('public_code')->map(fn ($c) => '/item/'.$c));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $u) {
            $xml .= '<url><loc>'.e($base.$u).'</loc></url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml']);
    }

    public function robots()
    {
        $body = Site::settings()->enabled()
            ? "User-agent: *\nDisallow: /search\nDisallow: /kk/search\nDisallow: /go/\nDisallow: /kk/go/\nSitemap: ".request()->getSchemeAndHttpHost()."/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }

    /** Signed link from the ERP «Открыть сайт» (§14): preview of a switched-off site for 8 hours. */
    public function preview(Request $request)
    {
        abort_unless(URL::hasValidSignature($request), 403);

        return redirect('/')->withCookie(cookie('pm_preview', StorefrontContext::previewToken(Site::$org->id), 480));
    }

    private function pageText(string $slug): ?SitePage
    {
        return SitePage::query()->where('slug', $slug)->first();
    }

    /** Ready HTML from the cache of the current version (§8.4); preview is never cached. */
    private function cached(int $ttl, \Closure $render, array $headers = [])
    {
        if (Site::$preview || app()->runningUnitTests()) {
            return response($render()->render(), 200, $headers);
        }
        $key = 'site:page:'.Site::$org->id.':'.ListingBuilder::version().':'.md5(request()->fullUrl());

        return response(Cache::remember($key, $ttl, fn () => $render()->render()), 200, $headers);
    }

    private function stat(string $kind, string $key): void
    {
        DB::table('site_stats')->upsert([['organization_id' => Site::$org->id, 'day' => now()->toDateString(), 'kind' => $kind, 'key' => $key, 'count' => 1]],
            ['organization_id', 'day', 'kind', 'key'], ['count' => DB::raw('site_stats.count + 1')]);
    }
}
