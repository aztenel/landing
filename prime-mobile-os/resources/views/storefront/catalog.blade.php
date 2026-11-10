@php
    use App\Http\Storefront\Site;
    use App\Support\Ui\Icon;
    $path = '/'.ltrim(request()->path(), '/');
    $query = request()->query();
    $sorts = ['pop' => 'Популярные', 'cheap' => 'Сначала дешевле', 'exp' => 'Сначала дороже', 'new' => 'Новые поступления'] + ($f['state'] === 'used' ? ['bat' => 'Батарея выше'] : []);
    $without = function (string $key, $value = null) use ($query, $path) {
        $q = $query;
        if ($value === null || ! is_array($q[$key] ?? null)) { unset($q[$key]); } else { $q[$key] = array_values(array_diff($q[$key], [(string) $value])); }
        unset($q['page']);
        return $path.($q ? '?'.http_build_query($q) : '');
    };
    $check = 'rounded border-input text-primary focus:ring-primary';
@endphp
@extends('storefront.layout', [
    'title' => $title.' — '.Site::t('купить в').' '.Site::city().' | '.Site::name(),
    'description' => $title.': '.$found.' — '.Site::name().', '.Site::city(),
    'noindex' => $noindex, 'tab' => 'catalog', 'searchValue' => $q,
    'canonical' => request()->getSchemeAndHttpHost().$path.(($f['mem'] && count($active) === 1 && ! $q) ? '?'.http_build_query(['mem' => $f['mem']]) : ''),
])
@section('content')
<div class="max-w-6xl mx-auto px-4 py-4">
    <nav class="text-[13px] text-muted-foreground mb-2" aria-label="breadcrumbs"><a href="{{ Site::route('home') }}" class="hover:text-foreground">{{ Site::t('Главная') }}</a> / <a href="{{ Site::route('catalog') }}" class="hover:text-foreground">{{ Site::t('Каталог') }}</a></nav>
    <div class="flex items-end justify-between gap-3 mb-3">
        <h1 class="text-[24px] sm:text-[28px] font-bold tracking-tight leading-tight">{{ $title }}</h1>
        <span class="text-[14px] text-muted-foreground shrink-0">{{ Site::t('Найдено :n', ['n' => $found]) }}</span>
    </div>

    @if ($active)
        <div class="flex flex-wrap gap-2 mb-3">
            @foreach ($active as $key)
                @foreach ((array) ($f[$key] === true ? [1] : $f[$key]) as $v)
                    @php($label = match ($key) { 'state' => Site::t($v === 'used' ? 'Б/у' : 'Новые'), 'brand' => $facets['brand'][$v][0] ?? $v, 'model' => $facets['model'][$v][0] ?? $v, 'mem' => $v.' ГБ', 'grade' => Site::grade($v), 'bat' => Site::t('от :n%', ['n' => $v]), 'sale' => Site::t('Скидка'), 'pmin' => Site::t('от').' '.Site::money($v), 'pmax' => Site::t('до').' '.Site::money($v), 'store' => Site::store($v)?->name, default => $v })
                    <a href="{{ $without($key, is_array($f[$key]) ? $v : null) }}" class="inline-flex items-center gap-1 rounded-full bg-white border border-border px-3 h-8 text-[13px]">{{ $label }} {!! Icon::svg('x', 'w-3.5 h-3.5') !!}</a>
                @endforeach
            @endforeach
            <a href="{{ $path }}{{ $q ? '?q='.urlencode($q) : '' }}" class="inline-flex items-center rounded-full px-3 h-8 text-[13px] text-primary font-medium">{{ Site::t('Сбросить всё') }}</a>
        </div>
    @endif

    <div class="grid grid-cols-[minmax(0,1fr)] lg:grid-cols-[250px_minmax(0,1fr)] gap-5 items-start">
        <details id="filters" class="bg-white rounded-2xl border border-border">
            <summary class="flex items-center justify-between px-4 h-12 font-semibold cursor-pointer lg:cursor-default">{{ Site::t('Фильтры') }} {!! Icon::svg('settings', 'w-5 h-5') !!}</summary>
            <form method="get" action="{{ $path }}" class="px-4 pb-4 space-y-4 text-[14px]">
                @if ($q)<input type="hidden" name="q" value="{{ $q }}">@endif
                <input type="hidden" name="sort" value="{{ $f['sort'] }}">
                @if ($facets['state']->isNotEmpty())
                    <fieldset><legend class="font-semibold mb-1.5">{{ Site::t('Состояние') }}</legend>
                        <div class="flex gap-2">
                            @foreach (['new' => 'Новые', 'used' => 'Б/у'] as $v => $n)
                                <label class="flex-1"><input type="radio" name="state" value="{{ $v }}" class="peer sr-only" @checked($f['state'] === $v)><span class="block text-center rounded-xl border border-border px-1 py-2.5 leading-tight peer-checked:border-primary peer-checked:text-primary peer-checked:font-semibold">{{ Site::t($n) }} <span class="text-muted-foreground">{{ $facets['state'][$v] ?? 0 }}</span></span></label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
                @foreach (['brand' => 'Бренд', 'model' => 'Модель'] as $key => $legend)
                    @if ($facets[$key]->count() > 0 && ($key === 'brand' || $f['brand'] || $facets['model']->count() <= 20))
                        <fieldset><legend class="font-semibold mb-1.5">{{ Site::t($legend) }}</legend>
                            @foreach ($facets[$key] as $v => [$n, $c])
                                <label class="flex items-center gap-2 py-1"><input type="checkbox" name="{{ $key }}[]" value="{{ $v }}" class="{{ $check }}" @checked(in_array($v, $f[$key], true))>{{ $n }} <span class="text-muted-foreground ml-auto">{{ $c }}</span></label>
                            @endforeach
                        </fieldset>
                    @endif
                @endforeach
                @if ($facets['mem']->isNotEmpty())
                    <fieldset><legend class="font-semibold mb-1.5">{{ Site::t('Память') }}</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($facets['mem'] as $gb => $c)
                                <label><input type="checkbox" name="mem[]" value="{{ $gb }}" class="peer sr-only" @checked(in_array((int) $gb, $f['mem'], true))><span class="inline-block rounded-xl border border-border px-3 h-9 leading-9 peer-checked:border-primary peer-checked:text-primary peer-checked:font-semibold">{{ $gb >= 1024 ? ($gb / 1024).' ТБ' : $gb.' ГБ' }}</span></label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
                @if ($facets['color']->isNotEmpty())
                    <fieldset><legend class="font-semibold mb-1.5">{{ Site::t('Цвет') }}</legend>
                        @foreach ($facets['color'] as $v => [$n, $c, $hex])
                            <label class="flex items-center gap-2 py-1"><input type="checkbox" name="color[]" value="{{ $v }}" class="{{ $check }}" @checked(in_array($v, $f['color'], true))><span class="w-4 h-4 rounded-full border border-border" style="background: {{ preg_match('/^#[0-9a-f]{3,8}$/i', (string) $hex) ? $hex : '#e5e7eb' }}"></span>{{ $n }} <span class="text-muted-foreground ml-auto">{{ $c }}</span></label>
                        @endforeach
                    </fieldset>
                @endif
                <fieldset><legend class="font-semibold mb-1.5">{{ Site::t('Цена') }}, ₸</legend>
                    <div class="flex gap-2"><input name="pmin" inputmode="numeric" value="{{ $f['pmin'] }}" placeholder="{{ Site::t('от') }}" class="w-1/2 min-w-0 rounded-xl border-input h-10 text-[14px]"><input name="pmax" inputmode="numeric" value="{{ $f['pmax'] }}" placeholder="{{ Site::t('до') }}" class="w-1/2 min-w-0 rounded-xl border-input h-10 text-[14px]"></div>
                </fieldset>
                @if ($facets['grade']->isNotEmpty())
                    <fieldset><legend class="font-semibold mb-1.5">{{ Site::t('Состояние б/у') }}</legend>
                        @foreach ($facets['grade']->sortKeys() as $g => $c)
                            <label class="flex items-center gap-2 py-1"><input type="checkbox" name="grade[]" value="{{ $g }}" class="{{ $check }}" @checked(in_array($g, $f['grade'], true))>{{ Site::grade($g) }} <span class="text-muted-foreground ml-auto">{{ $c }}</span></label>
                        @endforeach
                    </fieldset>
                    <fieldset><legend class="font-semibold mb-1.5">{{ Site::t('Батарея') }}</legend>
                        <div class="flex gap-2">
                            @foreach ([80, 90] as $b)
                                <label class="flex-1"><input type="radio" name="bat" value="{{ $b }}" class="peer sr-only" @checked($f['bat'] === $b)><span class="block text-center rounded-xl border border-border h-9 leading-9 peer-checked:border-primary peer-checked:text-primary peer-checked:font-semibold">{{ Site::t('от :n%', ['n' => $b]) }}</span></label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
                @if ($facets['store']->isNotEmpty())
                    <fieldset><legend class="font-semibold mb-1.5">{{ Site::t('Магазины') }}</legend>
                        @foreach ($facets['store'] as $ref => $c)
                            <label class="flex items-center gap-2 py-1"><input type="checkbox" name="store[]" value="{{ $ref }}" class="{{ $check }}" @checked(in_array((int) $ref, $f['store'], true))>{{ Site::store((int) $ref)?->name }} <span class="text-muted-foreground ml-auto">{{ $c }}</span></label>
                        @endforeach
                    </fieldset>
                @endif
                @if ($facets['sale'] > 0)
                    <label class="flex items-center gap-2"><input type="checkbox" name="sale" value="1" class="{{ $check }}" @checked($f['sale'])>{{ Site::t('Только со скидкой') }}</label>
                @endif
                <button class="w-full h-11 rounded-xl bg-primary text-white font-semibold">{{ Site::t('Показать') }}</button>
            </form>
        </details>

        <div class="min-w-0">
            <div class="scroll-x flex gap-2 mb-3 text-[13px]">
                @foreach ($sorts as $k => $n)
                    @php($sq = \Illuminate\Support\Arr::except(array_merge($query, ['sort' => $k]), 'page'))
                    <a href="{{ $path.'?'.http_build_query($sq) }}" rel="nofollow" class="shrink-0 rounded-full px-3 h-8 leading-8 border {{ $f['sort'] === $k ? 'bg-foreground text-white border-foreground' : 'bg-white border-border' }}">{{ Site::t($n) }}</a>
                @endforeach
            </div>
            @if ($items->isEmpty())
                <div class="bg-white rounded-2xl border border-border p-8 text-center">
                    <p class="text-[16px]">{{ $q ? Site::t('По запросу «:q» ничего нет. Спросите в WhatsApp — привезём под заказ', ['q' => $q]) : Site::t('Ничего не нашли. Напишите нам — найдём под заказ') }}</p>
                    <a href="{{ Site::route('wa', ['t' => 'to_order', 'q' => $empty_wa]) }}" rel="nofollow" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-wa text-white font-semibold px-5 h-12">@include('storefront.partials.wa-icon', ['class' => 'w-5 h-5']){{ Site::t('Спросить в WhatsApp') }}</a>
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2.5">
                    @foreach ($items as $l)
                        @include('storefront.partials.card', ['l' => $l])
                    @endforeach
                </div>
                @if ($items->hasPages())
                    <nav class="mt-5 flex items-center justify-center gap-2 text-[15px]">
                        @if ($items->hasMorePages())<a href="{{ $items->nextPageUrl() }}" class="rounded-xl bg-white border border-border px-5 h-11 leading-[44px] font-medium">{{ Site::t('Показать ещё') }}</a>@endif
                        @foreach (range(1, $items->lastPage()) as $p)
                            <a href="{{ $items->url($p) }}" class="w-10 h-10 grid place-items-center rounded-lg {{ $p === $items->currentPage() ? 'bg-foreground text-white' : 'bg-white border border-border' }}">{{ $p }}</a>
                        @endforeach
                    </nav>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script>if (window.matchMedia('(min-width: 1024px)').matches) { document.getElementById('filters').open = true; }</script>
@endpush
