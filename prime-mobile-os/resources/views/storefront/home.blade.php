@extends('storefront.layout', ['title' => App\Http\Storefront\Site::name().' — '.App\Http\Storefront\Site::t('Смартфоны').' '.mb_strtolower(App\Http\Storefront\Site::t('Новые')).' / '.mb_strtolower(App\Http\Storefront\Site::t('Б/у')).' — '.App\Http\Storefront\Site::city(), 'tab' => 'home'])
@php
    use App\Http\Storefront\Site;
    use App\Support\Ui\Icon;
    $store = Site::store();
@endphp
@push('head')
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => Site::name(), 'url' => request()->getSchemeAndHttpHost(), 'telephone' => $store?->phone], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
@section('content')
<div class="max-w-6xl mx-auto px-4 py-4 space-y-7">
    @if ($banners->isNotEmpty())
        <section class="scroll-x flex gap-3 -mx-4 px-4" aria-label="Баннеры">
            @foreach ($banners as $b)
                @php($t = Site::$lang === 'kk' && $b->title_kk ? $b->title_kk : $b->title_ru)
                @php($st = Site::$lang === 'kk' && $b->subtitle_kk ? $b->subtitle_kk : $b->subtitle_ru)
                <a href="{{ (Site::$lang === 'kk' && str_starts_with($b->link, '/') ? '/kk' : '').$b->link }}" class="relative shrink-0 w-[86%] sm:w-[60%] lg:w-[48%] aspect-square sm:aspect-[16/11] lg:aspect-[16/9] rounded-3xl overflow-hidden bg-gradient-to-br from-[#EEF0F6] to-[#DCE1EE] p-5 sm:p-7 flex flex-col">
                    <span class="absolute -right-12 -bottom-12 w-44 h-44 sm:w-72 sm:h-72 rounded-full bg-primary"></span>
                    <span class="absolute -right-20 -bottom-20 w-64 h-64 sm:w-96 sm:h-96 rounded-full border border-primary/30"></span>
                    <span class="relative text-[28px] sm:text-[40px] leading-[0.95] font-extrabold uppercase tracking-tight max-w-[75%]">{{ $t }}</span>
                    @if ($st)<span class="relative mt-3 text-[14px] sm:text-[16px] max-w-[62%]">{{ $st }}</span>@endif
                    @if ($b->price_from)<span class="relative mt-3 text-[18px] font-bold">{{ Site::t('от') }} {{ Site::money($b->price_from) }}</span>@endif
                    @if ($b->button_ru)<span class="relative mt-auto self-start rounded-xl bg-foreground text-white text-[14px] font-semibold px-4 py-2">{{ Site::$lang === 'kk' && $b->button_kk ? $b->button_kk : $b->button_ru }}</span>@endif
                </a>
            @endforeach
        </section>
    @endif

    @if ($tiles)
        <section class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-7 gap-2.5">
            @foreach ($tiles as $tile)
                <a href="{{ $tile['url'] }}" class="bg-white rounded-2xl border border-border px-2 py-4 flex flex-col items-center text-center gap-1.5 hover:border-primary/40">
                    <span class="w-11 h-11 rounded-full bg-[#EEF1FB] text-primary grid place-items-center">{!! Icon::svg($tile['icon'], 'w-6 h-6') !!}</span>
                    <span class="text-[14px] font-semibold leading-tight">{{ $tile['name'] }}</span>
                    <span class="text-[12px] text-muted-foreground">{{ $tile['n'] }}</span>
                </a>
            @endforeach
            <a href="{{ Site::route('tradein') }}" class="bg-white rounded-2xl border border-border px-2 py-4 flex flex-col items-center text-center gap-1.5 hover:border-primary/40">
                <span class="w-11 h-11 rounded-full bg-[#EEF1FB] text-primary grid place-items-center">{!! Icon::svg('repeat', 'w-6 h-6') !!}</span>
                <span class="text-[14px] font-semibold leading-tight">Trade-in</span>
            </a>
        </section>
    @endif

    @foreach ([['Новинки', $newest, Site::route('catalog', ['category' => 'smartfony']).'?state=new&sort=new'], ['Б/у с гарантией', $used, Site::route('used')], ['Хиты', $hits, Site::route('catalog')]] as [$name, $list, $all])
        @if ($list->isNotEmpty())
            <section>
                <div class="flex items-end justify-between mb-3">
                    <h2 class="text-[22px] font-bold tracking-tight">{{ Site::t($name) }}</h2>
                    <a href="{{ $all }}" class="text-[15px] font-medium text-primary">{{ Site::t('Все') }}</a>
                </div>
                <div class="scroll-x flex gap-2.5 -mx-4 px-4">
                    @foreach ($list as $l)
                        @include('storefront.partials.card', ['l' => $l, 'class' => 'shrink-0 w-[44%] sm:w-[30%] lg:w-[18.5%]'])
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach

    <section class="rounded-3xl bg-foreground text-white p-6 sm:p-8 grid sm:grid-cols-[1fr_auto] gap-4 items-center">
        <div>
            <h2 class="text-[24px] sm:text-[30px] font-extrabold uppercase leading-none">Trade-in</h2>
            <p class="mt-2 text-white/80 text-[15px]">{{ Site::t('Сдайте старый телефон в зачёт') }}</p>
        </div>
        <a href="{{ Site::route('tradein') }}" class="justify-self-start rounded-xl bg-white text-foreground font-semibold px-5 py-3">{{ Site::t('Подробнее') }}</a>
    </section>

    @if ($store)
        @include('storefront.partials.store', ['s' => $store])
    @endif
</div>
@endsection
