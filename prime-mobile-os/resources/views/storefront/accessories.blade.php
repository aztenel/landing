@php use App\Http\Storefront\Site; @endphp
@extends('storefront.layout', ['title' => Site::t('Аксессуары').' | '.Site::name(), 'tab' => 'catalog', 'noindex' => (bool) $cat])
@section('content')
<div class="max-w-6xl mx-auto px-4 py-4">
    <h1 class="text-[26px] font-bold tracking-tight mb-3">{{ Site::t('Аксессуары') }}</h1>
    <div class="scroll-x flex gap-2 mb-3 text-[13px]">
        <a href="{{ Site::route('catalog', ['category' => 'aksessuary']) }}" class="shrink-0 rounded-full px-3 h-8 leading-8 border {{ ! $cat ? 'bg-foreground text-white border-foreground' : 'bg-white border-border' }}">{{ Site::t('Все') }}</a>
        @foreach ($cats as $c)
            <a href="{{ Site::route('catalog', ['category' => 'aksessuary']) }}?cat={{ $c->category_slug }}" class="shrink-0 rounded-full px-3 h-8 leading-8 border {{ $cat === $c->category_slug ? 'bg-foreground text-white border-foreground' : 'bg-white border-border' }}">{{ Site::$lang === 'kk' ? $c->category_kk : $c->category_ru }} {{ $c->n }}</a>
        @endforeach
    </div>
    @if ($items->isEmpty())
        <div class="bg-white rounded-2xl border border-border p-8 text-center">{{ Site::t('Ничего не нашли. Напишите нам — найдём под заказ') }}</div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2.5">
            @foreach ($items as $a)
                <a href="{{ Site::route('item', ['code' => $a->public_code]) }}" class="bg-white rounded-2xl border border-border p-3 flex flex-col min-w-0">
                    <div class="aspect-square rounded-xl bg-[#F3F4F6] overflow-hidden">@if ($a->photo('md'))<img src="{{ $a->photo('md') }}" width="600" height="600" loading="lazy" alt="" class="w-full h-full object-cover">@endif</div>
                    <p class="mt-2.5 text-[14px] font-medium leading-snug line-clamp-2">{{ Site::$lang === 'kk' ? $a->title_kk : $a->title_ru }}</p>
                    <p class="mt-auto pt-2 text-[17px] font-bold">{{ Site::money($a->price) }}</p>
                    <p class="text-[12px] {{ $a->availability === 'in_stock' ? 'text-ok' : 'text-warn' }}">{{ Site::t($a->availability === 'in_stock' ? 'В наличии' : 'Под заказ') }}</p>
                </a>
            @endforeach
        </div>
        <div class="mt-5">{{ $items->links() }}</div>
    @endif
</div>
@endsection
