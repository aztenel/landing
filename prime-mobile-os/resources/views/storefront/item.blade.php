@php use App\Http\Storefront\Site; $title = $a->{'title_'.Site::$lang} ?: $a->title_ru; @endphp
@extends('storefront.layout', ['title' => $title.' — '.Site::money($a->price).' | '.Site::name(), 'description' => $title.', '.Site::money($a->price).'. '.Site::name().', '.Site::city(), 'tab' => 'catalog', 'ogImage' => $a->photo('og')])
@section('content')
<div class="max-w-6xl mx-auto px-4 py-4 grid grid-cols-[minmax(0,1fr)] lg:grid-cols-2 gap-5">
    <div class="bg-white rounded-2xl border border-border aspect-square overflow-hidden">@if ($a->photo('lg'))<img src="{{ $a->photo('lg') }}" width="1000" height="1000" fetchpriority="high" alt="{{ $title }}" class="w-full h-full object-cover">@endif</div>
    <div class="space-y-4 min-w-0">
        <h1 class="text-[24px] font-bold leading-tight">{{ $title }}</h1>
        <p class="text-[14px] text-muted-foreground font-mono">{{ $a->public_code }} · {{ Site::$lang === 'kk' ? $a->category_kk : $a->category_ru }}</p>
        <div class="bg-white rounded-2xl border border-border p-5">
            <p class="text-[28px] font-extrabold">{{ Site::money($a->price) }}</p>
            <p class="mt-1 text-[15px] {{ $a->availability === 'in_stock' ? 'text-ok' : 'text-warn' }}">{{ Site::t($a->availability === 'in_stock' ? 'В наличии' : 'Под заказ') }}</p>
            <a href="{{ Site::route('wa', ['t' => 'ask', 'code' => $a->public_code]) }}" rel="nofollow" class="mt-4 w-full inline-flex items-center justify-center gap-2 rounded-xl bg-wa text-white font-semibold h-12">@include('storefront.partials.wa-icon', ['class' => 'w-5 h-5']){{ Site::t('Написать в WhatsApp') }}</a>
        </div>
        @if ($store)@include('storefront.partials.store', ['s' => $store])@endif
    </div>
</div>
@endsection
