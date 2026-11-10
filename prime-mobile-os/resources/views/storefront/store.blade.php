@php use App\Http\Storefront\Site; @endphp
@extends('storefront.layout', ['title' => Site::t('Магазин').' — '.Site::name().', '.Site::city(), 'tab' => 'store'])
@push('head')
@foreach ($stores as $s)
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'Store', 'name' => $s->name, 'telephone' => $s->phone, 'openingHours' => $s->hours,
    'address' => ['@type' => 'PostalAddress', 'streetAddress' => $s->address_ru, 'addressLocality' => $s->city ?: Site::city(), 'addressCountry' => 'KZ']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endforeach
@endpush
@section('content')
<div class="max-w-6xl mx-auto px-4 py-4 space-y-3">
    <h1 class="text-[26px] font-bold tracking-tight">{{ Site::t($stores->count() > 1 ? 'Магазины' : 'Магазин') }}</h1>
    @foreach ($stores as $s)
        @include('storefront.partials.store', ['s' => $s])
    @endforeach
</div>
@endsection
