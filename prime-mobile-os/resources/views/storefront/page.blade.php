@php
    use App\Http\Storefront\Site;
    $body = $page ? ((Site::$lang === 'kk' && $page->body_kk) ? $page->body_kk : $page->body_ru) : null;
    $body ??= App\Domain\Storefront\Services\SitePages::DEFAULTS[$slug]['body'] ?? '';
@endphp
@extends('storefront.layout', ['title' => $title.' | '.Site::name()])
@section('content')
<div class="max-w-3xl mx-auto px-4 py-6">
    <h1 class="text-[26px] font-bold tracking-tight mb-4">{{ $page && Site::$lang === 'kk' && $page->title_kk ? $page->title_kk : ($page->title_ru ?? $title) }}</h1>
    <article class="prose-site bg-white rounded-2xl border border-border p-5 text-[15px] leading-relaxed">{!! App\Domain\Storefront\Services\SitePages::render($body) !!}</article>
    @if ($slug === 'rassrochka')
        @php($p = Site::settings()->get('installment'))
        <div class="mt-4 bg-white rounded-2xl border border-border p-5 text-[15px]">
            <p class="font-semibold">{{ $p['title_'.Site::$lang] ?? $p['title_ru'] }}</p>
            <p class="mt-1 text-muted-foreground">{{ Site::t('Срок') }}: {{ implode(' / ', array_map(fn ($m) => Site::months((int) $m), $p['months'])) }} · {{ Site::money((int) $p['min']) }} – {{ Site::money((int) $p['max']) }}</p>
            <p class="mt-2 text-[12px] text-muted-foreground">{{ Site::t('Условия банка, не является офертой. Окончательное решение — банк') }}</p>
        </div>
    @endif
</div>
@endsection
