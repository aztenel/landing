@php use App\Http\Storefront\Site; @endphp
@extends('storefront.layout', ['title' => Site::t('Страница не найдена').' | '.Site::name(), 'noindex' => true])
@section('content')
<div class="max-w-xl mx-auto px-4 py-16 text-center">
    <p class="text-[56px] font-extrabold leading-none">404</p>
    <p class="mt-3 text-[18px]">{{ Site::t('Страница не найдена') }}</p>
    <a href="{{ Site::route('home') }}" class="mt-6 inline-flex rounded-xl bg-primary text-white font-semibold px-5 h-12 items-center">{{ Site::t('На главную') }}</a>
</div>
@endsection
