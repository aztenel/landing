@php
    use App\Http\Storefront\Site;
    use App\Domain\Storefront\Services\SiteSettings;
    use App\Support\Ui\Icon;
    /** @var \App\Domain\Storefront\Models\Listing $l */
    $title = $l->title(Site::$lang);
    $sold = $l->public_status === 'sold';
    $used = $l->state === 'used';
    $grade = Site::grade($l->grade);
    $base = request()->getSchemeAndHttpHost();
    $city = Site::city();
    $pageTitle = $used
        ? $title.' '.Site::t('Б/у').($grade ? ', '.$grade : '').($l->battery_pct ? ', '.Site::t('Батарея :n%', ['n' => $l->battery_pct]) : '').($sold ? '' : ' — '.Site::money($l->price)).' | '.Site::name().', '.$city
        : $title.($sold ? '' : ' — '.Site::money($l->price)).' | '.Site::name().', '.$city;
    $desc = trim(($used ? Site::t('Б/у').' '.$title.($grade ? ', '.$grade : '').($l->battery_pct ? ', '.Site::t('Батарея :n%', ['n' => $l->battery_pct]) : '') : Site::t('Новый, запечатанный').' '.$title)
        .($l->warranty_months ? '. '.Site::t('Гарантия :n мес', ['n' => $l->warranty_months]) : '')
        .($l->installment_from && ! $sold ? '. '.Site::t('Рассрочка от :sum', ['sum' => Site::money($l->installment_from)]) : '').'. '.Site::t('Код').' '.$l->public_code.'.');
    $canonical = $used || ! $l->model_slug ? $base.(Site::$lang === 'kk' ? '/kk' : '').'/phone/'.$l->public_code : $base.(Site::$lang === 'kk' ? '/kk' : '').'/model/'.$l->model_slug;
    $photos = (array) $l->photos;
    $address = $store ? (Site::$lang === 'kk' && $store->address_kk ? $store->address_kk : $store->address_ru) : null;
@endphp
@extends('storefront.layout', ['title' => $pageTitle, 'description' => $desc, 'canonical' => $canonical, 'tab' => 'catalog',
    'ogTitle' => $title.($sold ? '' : ' — '.Site::money($l->price)), 'ogImage' => $l->photo('og')])
@push('head')
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $title, 'sku' => $l->public_code,
    'image' => array_values(array_filter(array_map(fn ($p) => isset($p['lg']) ? $base.'/storage/'.$p['lg'] : null, $photos))), 'brand' => ['@type' => 'Brand', 'name' => $l->brand],
    'itemCondition' => $used ? 'https://schema.org/UsedCondition' : 'https://schema.org/NewCondition',
    'offers' => ['@type' => 'Offer', 'price' => $l->price, 'priceCurrency' => 'KZT', 'url' => $base.'/phone/'.$l->public_code,
        'availability' => 'https://schema.org/'.($sold ? 'SoldOut' : ($l->public_status === 'reserved' ? 'LimitedAvailability' : 'InStock')), 'seller' => ['@type' => 'Organization', 'name' => Site::name()]]],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => Site::t('Каталог'), 'item' => $base.Site::route('catalog')],
    ['@type' => 'ListItem', 'position' => 2, 'name' => (string) $l->model_name, 'item' => $base.Site::route('model', ['slug' => $l->model_slug ?: 'x'])],
    ['@type' => 'ListItem', 'position' => 3, 'name' => $title]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
@section('content')
<div class="max-w-6xl mx-auto px-4 py-4">
    <nav class="text-[13px] text-muted-foreground mb-2" aria-label="breadcrumbs"><a href="{{ Site::route('catalog') }}" class="hover:text-foreground">{{ Site::t('Каталог') }}</a>
        @if ($l->model_slug) / <a href="{{ Site::route('model', ['slug' => $l->model_slug]) }}" class="hover:text-foreground">{{ $l->model_name }}</a>@endif</nav>
    <div class="grid grid-cols-[minmax(0,1fr)] lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] gap-5 lg:gap-8 items-start">
        <div class="lg:sticky lg:top-4 min-w-0">
            <div class="lg:hidden mb-3">@include('storefront.partials.phone-head')</div>
            <div class="relative bg-white rounded-2xl border border-border overflow-hidden">
                @if ($photos)
                    <div class="scroll-x flex" id="gallery">
                        @foreach ($photos as $i => $p)
                            <a id="p{{ $i + 1 }}" href="/storage/{{ $p['xl'] ?? $p['lg'] }}" class="shrink-0 w-full aspect-square bg-[#F3F4F6]">
                                <img src="/storage/{{ $p['lg'] }}" srcset="/storage/{{ $p['md'] }} 600w, /storage/{{ $p['lg'] }} 1000w" sizes="(min-width: 1024px) 560px, 100vw" width="1000" height="1000"
                                     alt="{{ $title }} — {{ $i + 1 }}" class="w-full h-full object-cover" @if ($i === 0) fetchpriority="high" @else loading="lazy" @endif>
                            </a>
                        @endforeach
                    </div>
                @endif
                @if ($sold)<span class="absolute left-3 top-3 rounded-lg bg-foreground text-white font-semibold px-3 py-1">{{ Site::t('Продан') }}</span>@endif
            </div>
            @if (count($photos) > 1)
                <div class="scroll-x flex gap-2 mt-2">
                    @foreach ($photos as $i => $p)
                        <a href="#p{{ $i + 1 }}" class="shrink-0 w-[68px] h-[68px] rounded-xl overflow-hidden border border-border bg-white"><img src="/storage/{{ $p['sm'] }}" width="300" height="300" loading="lazy" alt="" class="w-full h-full object-cover"></a>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="space-y-4 min-w-0">
            <div class="hidden lg:block">@include('storefront.partials.phone-head')</div>

            @if ($sold)
                <div class="bg-white rounded-2xl border border-border p-5">
                    <p class="text-[17px] font-semibold">{{ Site::t('Вы смотрите проданный телефон') }}</p>
                    <a href="{{ Site::route('wa', ['t' => 'similar', 'code' => $l->public_code]) }}" rel="nofollow" class="mt-3 w-full inline-flex items-center justify-center gap-2 rounded-xl bg-wa text-white font-semibold h-12">@include('storefront.partials.wa-icon', ['class' => 'w-5 h-5']){{ Site::t('Подберите мне похожий') }}</a>
                </div>
            @else
                <div class="bg-white rounded-2xl border border-border p-5">
                    <p class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-[30px] font-extrabold tracking-tight">{{ Site::money($l->price) }}</span>
                        @if ($l->compare_at_price > $l->price)<s class="text-[17px] text-muted-foreground">{{ Site::money($l->compare_at_price) }}</s><span class="rounded-md bg-bad text-white text-[13px] font-semibold px-1.5 py-0.5">−{{ $l->discountPercent() }}%</span>@endif
                    </p>
                    @if ($l->installment_from)<p class="text-[15px] text-muted-foreground">{{ Site::t('от :sum × :m', ['sum' => Site::money($l->installment_from), 'm' => Site::months($l->installment_months)]) }}</p>@endif
                    <p class="mt-3 flex items-start gap-2 text-[15px] {{ $l->public_status === 'reserved' ? 'text-warn' : 'text-ok' }}">
                        {!! Icon::svg($l->public_status === 'reserved' ? 'clock' : 'check', 'w-5 h-5 shrink-0 mt-0.5') !!}
                        <span>{{ $l->public_status === 'reserved' ? Site::t('Забронирован').' — '.Site::t('Спросить в WhatsApp') : Site::t('В наличии').($store ? ' — '.$store->name.($address ? ', '.$address : '') : '') }}
                            @if ($inStock > 1)<b class="block">{{ Site::t('В наличии :n шт.', ['n' => $inStock]) }}</b>@endif</span>
                    </p>
                    <div class="mt-4 grid gap-2">
                        @if ($l->public_status === 'reserved')
                            <a href="{{ Site::route('wa', ['t' => 'similar', 'code' => $l->public_code]) }}" rel="nofollow" class="inline-flex items-center justify-center gap-2 rounded-xl bg-wa text-white font-semibold h-12">@include('storefront.partials.wa-icon', ['class' => 'w-5 h-5']){{ Site::t('Подберите мне похожий') }}</a>
                        @else
                            <a href="{{ Site::route('wa', ['t' => 'ask', 'code' => $l->public_code]) }}" rel="nofollow" class="inline-flex items-center justify-center gap-2 rounded-xl bg-wa text-white font-semibold h-12">@include('storefront.partials.wa-icon', ['class' => 'w-5 h-5']){{ Site::t('Написать в WhatsApp') }}</a>
                            <div class="grid grid-cols-2 gap-2">
                                <a href="{{ Site::route('wa', ['t' => 'book', 'code' => $l->public_code]) }}" rel="nofollow" class="inline-flex items-center justify-center rounded-xl border border-border font-semibold h-12">{{ Site::t('Забронировать') }}</a>
                                @if ($store?->phone)<a href="tel:{{ preg_replace('/[^\d+]/', '', $store->phone) }}" class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-border font-medium h-12">{!! Icon::svg('call', 'w-5 h-5') !!}{{ Site::t('Позвонить') }}</a>@endif
                            </div>
                        @endif
                    </div>
                </div>

                @if ($terms && $l->public_status !== 'reserved')
                    <form method="get" action="{{ Site::route('wa') }}" class="bg-white rounded-2xl border border-border p-5">
                        <input type="hidden" name="t" value="installment"><input type="hidden" name="code" value="{{ $l->public_code }}">
                        <p class="font-semibold">{{ Site::settings()->get('installment.title_'.Site::$lang) ?: Site::settings()->get('installment.title_ru') }}</p>
                        <div class="mt-3 grid grid-cols-4 gap-2">
                            @foreach ($terms as $i => $t)
                                <label><input type="radio" name="m" value="{{ $t['months'] }}" class="peer sr-only" @checked($i === count($terms) - 1)>
                                    <span class="block text-center rounded-xl border border-border py-2 peer-checked:border-primary peer-checked:bg-primary/5">
                                        <span class="block text-[13px] text-muted-foreground">{{ Site::months($t['months']) }}</span>
                                        <span class="block text-[14px] font-semibold">{{ number_format($t['payment'], 0, '', ' ') }} ₸</span></span></label>
                            @endforeach
                        </div>
                        <button class="mt-3 w-full h-12 rounded-xl bg-primary text-white font-semibold">{{ Site::t('Купить в рассрочку') }}</button>
                        <p class="mt-2 text-[12px] text-muted-foreground">{{ Site::t('Условия банка, не является офертой. Окончательное решение — банк') }}</p>
                    </form>
                @endif
            @endif

            @if ($used)
                <dl class="bg-white rounded-2xl border border-border divide-y divide-border text-[15px]">
                    @if ($grade)<div class="px-5 py-3 grid grid-cols-[130px_minmax(0,1fr)] gap-3"><dt class="text-muted-foreground">{{ Site::t('Состояние') }}</dt><dd><b>{{ $l->grade }} · {{ $grade }}</b> <span class="block text-muted-foreground text-[14px]">{{ Site::settings()->gradeText($l->grade, Site::$lang) }}</span></dd></div>@endif
                    @if ($l->battery_pct)<div class="px-5 py-3 grid grid-cols-[130px_minmax(0,1fr)] gap-3"><dt class="text-muted-foreground">{{ Site::t('Аккумулятор') }}</dt><dd>{{ $l->battery_pct }}%@if ($l->battery_pct < 80) — <span class="text-warn">{{ Site::t('рекомендуем замену батареи') }}</span>@endif</dd></div>@endif
                    @if ($l->release_year)<div class="px-5 py-3 grid grid-cols-[130px_minmax(0,1fr)] gap-3"><dt class="text-muted-foreground">{{ Site::t('Год выпуска') }}</dt><dd>{{ $l->release_year }}</dd></div>@endif
                    @if ($d = (Site::$lang === 'kk' && $l->description_kk ? $l->description_kk : $l->description_ru))<div class="px-5 py-3 grid grid-cols-[130px_minmax(0,1fr)] gap-3"><dt class="text-muted-foreground">{{ Site::t('Дефекты') }}</dt><dd>{{ $d }}</dd></div>@endif
                    @if ($l->public_checks)<div class="px-5 py-3 grid grid-cols-[130px_minmax(0,1fr)] gap-3"><dt class="text-muted-foreground">{{ Site::t('Проверено') }}</dt><dd class="space-y-0.5">@foreach ($l->public_checks as $c)<span class="flex items-center gap-1.5">{!! Icon::svg('check', 'w-4 h-4 text-ok') !!}{{ SiteSettings::PUBLIC_CHECKS[$c][Site::$lang === 'kk' ? 1 : 0] ?? $c }}</span>@endforeach</dd></div>@endif
                    @if ($l->imei_verified)<div class="px-5 py-3 grid grid-cols-[130px_minmax(0,1fr)] gap-3"><dt class="text-muted-foreground">{{ Site::t('Госбаза IMEI') }}</dt><dd class="flex items-center gap-1.5">{!! Icon::svg('shield', 'w-4 h-4 text-ok') !!}{{ Site::t('Проверен в госбазе IMEI') }}</dd></div>@endif
                    @if ($l->warranty_months)<div class="px-5 py-3 grid grid-cols-[130px_minmax(0,1fr)] gap-3"><dt class="text-muted-foreground">{{ Site::t('Гарантия магазина') }}</dt><dd>{{ Site::t(':n мес', ['n' => $l->warranty_months]) }}</dd></div>@endif
                </dl>
            @else
                <div class="bg-white rounded-2xl border border-border p-5 text-[15px] space-y-2">
                    <p class="flex items-center gap-2">{!! Icon::svg('box', 'w-5 h-5 text-muted-foreground') !!}{{ Site::t('Новый, запечатанный') }}@if ($l->warranty_months) · {{ Site::t('Гарантия :n мес', ['n' => $l->warranty_months]) }}@endif</p>
                    @if ($l->imei_verified)<p class="flex items-center gap-2">{!! Icon::svg('shield', 'w-5 h-5 text-ok') !!}{{ Site::t('Проверен в госбазе IMEI') }}</p>@endif
                    @if ($variants->count() > 1)
                        <p class="pt-2 font-semibold">{{ Site::t('Память') }} / {{ Site::t('Цвет') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($variants as $v)
                                <a href="{{ Site::route('phone', ['code' => $v->public_code]) }}" class="inline-flex items-center gap-1.5 rounded-xl border px-3 h-10 text-[14px] {{ $v->group_key === $l->group_key ? 'border-primary text-primary font-semibold' : 'border-border' }}">
                                    <span class="w-3.5 h-3.5 rounded-full border border-border" style="background: {{ preg_match('/^#[0-9a-f]{3,8}$/i', (string) $v->color_hex) ? $v->color_hex : '#e5e7eb' }}"></span>{{ $v->memory }} · {{ Site::$lang === 'kk' ? $v->color_kk : $v->color_ru }}</a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            @if ($store)@include('storefront.partials.store', ['s' => $store, 'stack' => true])@endif
            <div class="grid grid-cols-2 gap-2 text-[14px]">
                <a href="{{ Site::route('page', ['slug' => 'garantiya']) }}" class="bg-white rounded-xl border border-border px-4 py-3 font-medium">{{ Site::t('Гарантия, обмен и возврат') }}</a>
                <a href="{{ Site::route('tradein', ['want' => $sold ? null : $l->public_code]) }}" class="bg-white rounded-xl border border-border px-4 py-3 font-medium">Trade-in: {{ mb_strtolower(Site::t('Сдайте старый телефон в зачёт')) }}</a>
            </div>
        </div>
    </div>

    @if ($similar->isNotEmpty())
        <section class="mt-8">
            <h2 class="text-[22px] font-bold tracking-tight mb-3">{{ Site::t($sold ? 'Похожие в наличии' : 'Похожие') }}</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2.5">
                @foreach ($similar as $s)
                    @include('storefront.partials.card', ['l' => $s])
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
@push('scripts')
<script>
document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () { if (navigator.clipboard) { navigator.clipboard.writeText(b.getAttribute('data-copy')); b.classList.add('text-ok'); } });
});
</script>
@endpush
