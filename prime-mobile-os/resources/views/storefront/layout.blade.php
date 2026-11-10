@php
    use App\Http\Storefront\Site;
    use App\Support\Ui\Icon;
    $main = Site::store();
    $installment = (bool) Site::settings()->get('installment.enabled');
    $path = '/'.ltrim(preg_replace('#^kk(/|$)#', '', request()->path()), '/');
    $base = request()->getSchemeAndHttpHost();
    $q = request()->getQueryString();
@endphp
<!doctype html>
<html lang="{{ Site::$lang }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{{ $title ?? Site::name() }}</title>
@isset($description)<meta name="description" content="{{ $description }}">@endisset
@if (! empty($noindex) || Site::$preview)<meta name="robots" content="noindex, follow">@endif
<link rel="canonical" href="{{ $canonical ?? $base.(Site::$lang === 'kk' ? '/kk' : '').($path === '/' ? '' : $path) }}">
<link rel="alternate" hreflang="ru" href="{{ $base.$path }}">
<link rel="alternate" hreflang="kk" href="{{ $base.'/kk'.($path === '/' ? '' : $path) }}">
<link rel="alternate" hreflang="x-default" href="{{ $base.$path }}">
<meta property="og:site_name" content="{{ Site::name() }}">
<meta property="og:locale" content="{{ Site::$lang === 'kk' ? 'kk_KZ' : 'ru_RU' }}">
<meta property="og:title" content="{{ $ogTitle ?? $title ?? Site::name() }}">
@isset($description)<meta property="og:description" content="{{ $description }}">@endisset
@isset($ogImage)<meta property="og:image" content="{{ $base.$ogImage }}"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">@endisset
<meta name="theme-color" content="#ffffff">
<link rel="icon" href="/icons/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
@vite('resources/css/storefront.css')
@stack('head')
</head>
<body class="min-h-screen flex flex-col pb-[68px] lg:pb-0">
@if (Site::$preview && ! Site::settings()->enabled())
    <div class="bg-warn text-white text-center text-[13px] font-medium py-1.5 px-4">{{ Site::t('Сайт ещё не открыт для покупателей') }}</div>
@endif
<a href="{{ Site::route('tradein') }}" class="block bg-foreground text-white text-center text-[13px] sm:text-sm py-2.5 px-4">{{ Site::t('Trade-in: сдайте старый телефон в зачёт нового') }}</a>
<header class="bg-white border-b border-border">
    <div class="max-w-6xl mx-auto px-4 pt-3 pb-3 flex items-center gap-1.5 sm:gap-3">
        <a href="{{ Site::route('home') }}" class="mr-auto" aria-label="{{ Site::name() }}"><x-ui::logo size="lg" class="hidden sm:inline-flex" /><x-ui::logo class="sm:hidden" /></a>
        @if ($main?->phone)
            <a href="tel:{{ preg_replace('/[^\d+]/', '', $main->phone) }}" class="w-10 h-10 grid place-items-center rounded-full hover:bg-muted" aria-label="{{ Site::t('Позвонить') }}">{!! Icon::svg('call', 'w-[22px] h-[22px]') !!}</a>
        @endif
        @if ($installment)
            <a href="{{ Site::route('page', ['slug' => 'rassrochka']) }}" class="inline-flex h-10 items-center rounded-xl bg-primary text-white font-semibold px-3 sm:px-4 text-[14px] sm:text-[15px]">{{ Site::t('Рассрочка') }}</a>
        @endif
        <a href="{{ Site::switchUrl() }}" class="h-10 px-2.5 grid place-items-center rounded-lg border border-border text-[13px] font-semibold" hreflang="{{ Site::$lang === 'kk' ? 'ru' : 'kk' }}">{{ Site::$lang === 'kk' ? 'RU' : 'KZ' }}</a>
        <details class="relative lg:hidden">
            <summary class="w-10 h-10 grid place-items-center rounded-lg cursor-pointer" aria-label="Меню">{!! Icon::svg('menu', 'w-6 h-6') !!}</summary>
            <nav class="absolute right-0 top-12 z-30 w-64 bg-white border border-border rounded-xl shadow-lg p-2 text-[15px]">
                @foreach (Site::categories() as $slug => [$type, $name])
                    <a href="{{ Site::route('catalog', ['category' => $slug]) }}" class="block px-3 py-2.5 rounded-lg hover:bg-muted">{{ Site::t($name) }}</a>
                @endforeach
                <a href="{{ Site::route('used') }}" class="block px-3 py-2.5 rounded-lg hover:bg-muted">{{ Site::t('Б/у') }}</a>
                <a href="{{ Site::route('catalog', ['category' => 'aksessuary']) }}" class="block px-3 py-2.5 rounded-lg hover:bg-muted">{{ Site::t('Аксессуары') }}</a>
                <div class="border-t border-border my-1"></div>
                <a href="{{ Site::route('tradein') }}" class="block px-3 py-2.5 rounded-lg hover:bg-muted">Trade-in</a>
                @if ($installment)<a href="{{ Site::route('page', ['slug' => 'rassrochka']) }}" class="block px-3 py-2.5 rounded-lg hover:bg-muted">{{ Site::t('Рассрочка') }}</a>@endif
                <a href="{{ Site::route('store') }}" class="block px-3 py-2.5 rounded-lg hover:bg-muted">{{ Site::t('Магазин') }}</a>
                <a href="{{ Site::route('page', ['slug' => 'garantiya']) }}" class="block px-3 py-2.5 rounded-lg hover:bg-muted">{{ Site::t('Гарантия, обмен и возврат') }}</a>
            </nav>
        </details>
    </div>
    <form action="{{ Site::route('search') }}" method="get" class="max-w-6xl mx-auto px-4 pb-3 flex" role="search">
        <input type="search" name="q" value="{{ $searchValue ?? '' }}" placeholder="{{ Site::t('iPhone 15, Samsung S24, PM-код…') }}" aria-label="{{ Site::t('Поиск') }}"
               class="flex-1 min-w-0 h-12 rounded-l-2xl border border-r-0 border-input px-4 text-base focus:ring-primary focus:border-primary">
        <button class="w-14 h-12 rounded-r-2xl bg-foreground text-white grid place-items-center" aria-label="{{ Site::t('Поиск') }}">{!! Icon::svg('search', 'w-6 h-6') !!}</button>
    </form>
    <nav class="hidden lg:block border-t border-border">
        <div class="max-w-6xl mx-auto px-4 flex gap-6 text-[15px] font-medium h-11 items-center">
            @foreach (Site::categories() as $slug => [$type, $name])
                <a href="{{ Site::route('catalog', ['category' => $slug]) }}" class="hover:text-primary">{{ Site::t($name) }}</a>
            @endforeach
            <a href="{{ Site::route('used') }}" class="hover:text-primary">{{ Site::t('Б/у') }}</a>
            <a href="{{ Site::route('catalog', ['category' => 'aksessuary']) }}" class="hover:text-primary">{{ Site::t('Аксессуары') }}</a>
            <a href="{{ Site::route('tradein') }}" class="hover:text-primary">Trade-in</a>
            <a href="{{ Site::route('store') }}" class="hover:text-primary ml-auto">{{ Site::t('Магазин') }}</a>
        </div>
    </nav>
</header>

<main class="flex-1 bg-[#F6F7F9]">@yield('content')</main>

<footer class="bg-white border-t border-border">
    <div class="max-w-6xl mx-auto px-4 py-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3 text-[15px]">
        <div class="space-y-1.5">
            <x-ui::logo />
            @if ($main)
                <p class="pt-2">{{ Site::$lang === 'kk' && $main->address_kk ? $main->address_kk : $main->address_ru }}</p>
                @if ($main->hours)<p class="text-muted-foreground">{{ $main->hours }}</p>@endif
            @endif
        </div>
        <div class="space-y-1.5">
            @if ($main?->phone)<p><a href="tel:{{ preg_replace('/[^\d+]/', '', $main->phone) }}" class="hover:text-primary">{{ $main->phone }}</a></p>@endif
            @if ($main?->whatsapp)<p><a href="{{ Site::route('wa') }}" class="text-wa font-medium" rel="nofollow">WhatsApp</a></p>@endif
            @if (Site::settings()->get('instagram'))<p><a href="https://instagram.com/{{ ltrim(Site::settings()->get('instagram'), '@') }}" rel="noopener" class="hover:text-primary">Instagram</a></p>@endif
            @if ($main?->map_url)<p><a href="{{ $main->map_url }}" rel="noopener" class="hover:text-primary">2ГИС</a></p>@endif
        </div>
        <div class="space-y-1.5">
            <p><a href="{{ Site::route('page', ['slug' => 'garantiya']) }}" class="hover:text-primary">{{ Site::t('Гарантия, обмен и возврат') }}</a></p>
            <p><a href="{{ Site::route('page', ['slug' => 'privacy']) }}" class="hover:text-primary">{{ Site::t('Политика конфиденциальности') }}</a></p>
            <p class="text-muted-foreground pt-2">© {{ Site::name() }}</p>
        </div>
    </div>
</footer>

<nav class="lg:hidden fixed bottom-0 inset-x-0 z-40 bg-white border-t border-border grid grid-cols-5 text-[11px] pb-[env(safe-area-inset-bottom)]">
    @php($tab = $tab ?? null)
    <a href="{{ Site::route('home') }}" class="flex flex-col items-center gap-0.5 pt-2 pb-1.5 {{ $tab === 'home' ? 'text-foreground font-semibold' : 'text-muted-foreground' }}">{!! Icon::svg('home', 'w-6 h-6') !!}{{ Site::t('Главная') }}</a>
    <a href="{{ Site::route('catalog') }}" class="flex flex-col items-center gap-0.5 pt-2 pb-1.5 {{ $tab === 'catalog' ? 'text-foreground font-semibold' : 'text-muted-foreground' }}">{!! Icon::svg('list', 'w-6 h-6') !!}{{ Site::t('Каталог') }}</a>
    <a href="{{ Site::route('tradein') }}" class="flex flex-col items-center gap-0.5 pt-2 pb-1.5 {{ $tab === 'tradein' ? 'text-foreground font-semibold' : 'text-muted-foreground' }}">{!! Icon::svg('repeat', 'w-6 h-6') !!}Trade-in</a>
    <a href="{{ Site::route('store') }}" class="flex flex-col items-center gap-0.5 pt-2 pb-1.5 {{ $tab === 'store' ? 'text-foreground font-semibold' : 'text-muted-foreground' }}">{!! Icon::svg('store', 'w-6 h-6') !!}{{ Site::t('Магазин') }}</a>
    <a href="{{ Site::route('wa') }}" rel="nofollow" class="flex flex-col items-center gap-0.5 pt-2 pb-1.5 text-wa font-medium">@include('storefront.partials.wa-icon', ['class' => 'w-6 h-6'])WhatsApp</a>
</nav>
@stack('scripts')
</body>
</html>
