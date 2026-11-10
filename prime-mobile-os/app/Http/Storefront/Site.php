<?php

namespace App\Http\Storefront;

use App\Domain\Storefront\Models\SiteStore;
use App\Domain\Storefront\Services\SiteSettings;
use App\Domain\Tenancy\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Request state of the public site: firm (by domain), language (ru — no prefix, kk — /kk, TZ 05 §13.3), settings,
 * stores. Interface texts ru → kk live in KK (Допущение: перевод интерфейса делает интегратор, Кайрат проверяет).
 */
final class Site
{
    public static string $lang = 'ru';

    public static ?Organization $org = null;

    public static bool $preview = false;

    private static ?SiteSettings $settings = null;

    private static ?Collection $stores = null;

    public static function boot(Organization $org, string $lang, bool $preview): void
    {
        self::$org = $org;
        self::$lang = $lang;
        self::$preview = $preview;
        self::$settings = null;
        self::$stores = null;
    }

    public static function settings(): SiteSettings
    {
        return self::$settings ??= SiteSettings::current();
    }

    /** @return Collection<int, SiteStore> */
    public static function stores(): Collection
    {
        return self::$stores ??= SiteStore::query()->orderByDesc('is_main')->orderBy('id')->get();
    }

    public static function store(?int $ref = null): ?SiteStore
    {
        return ($ref ? self::stores()->firstWhere('store_ref', $ref) : null) ?? self::stores()->first();
    }

    public static function name(): string
    {
        return (string) (self::$org?->settings()->get('brand.display_name') ?: self::$org?->name ?: 'Prime Mobile');
    }

    public static function city(): string
    {
        return (string) self::settings()->get('city_'.self::$lang) ?: (string) self::settings()->get('city_ru');
    }

    public static function t(string $ru, array $replace = []): string
    {
        $s = self::$lang === 'kk' ? (KK::WORDS[$ru] ?? $ru) : $ru;
        foreach ($replace as $k => $v) {
            $s = str_replace(':'.$k, (string) $v, $s);
        }

        return $s;
    }

    public static function route(string $name, array $params = []): string
    {
        return route('storefront.'.(self::$lang === 'kk' ? 'kk.' : '').$name, $params, false);
    }

    /** The same page in the other language (§13.3 переключатель). */
    public static function switchUrl(): string
    {
        $path = '/'.ltrim(request()->path(), '/');
        $path = self::$lang === 'kk' ? (preg_replace('#^/kk(/|$)#', '/', $path) ?: '/') : '/kk'.($path === '/' ? '' : $path);
        $q = request()->getQueryString();

        return $path.($q ? '?'.$q : '');
    }

    public static function money(?int $tenge): string
    {
        return $tenge === null ? '' : number_format($tenge, 0, '', "\u{00A0}")."\u{00A0}₸";
    }

    public static function months(int $n): string
    {
        return $n.' '.(self::$lang === 'kk' ? 'ай' : 'мес');
    }

    public static function grade(?string $g): ?string
    {
        return self::settings()->grade($g, self::$lang);
    }

    public static function wa(?string $phone): string
    {
        return 'https://wa.me/'.preg_replace('/\D/', '', (string) $phone);
    }

    public static function categories(): array
    {
        return [
            'smartfony' => ['smartphone', 'Смартфоны', 'phone'],
            'planshety' => ['tablet', 'Планшеты', 'tablet'],
            'chasy' => ['watch', 'Часы', 'watch'],
            'naushniki' => ['headphones', 'Наушники', 'headphones'],
        ];
    }
}
