@php
    use App\Http\Storefront\Site;
    $opt = fn ($name, $values) => collect($values)->map(fn ($v) => '<label><input type="radio" name="'.$name.'" value="'.e($v).'" class="peer sr-only"><span class="inline-block rounded-xl border border-border px-3 h-10 leading-10 text-[14px] peer-checked:border-primary peer-checked:text-primary peer-checked:font-semibold">'.e($v).'</span></label>')->implode('');
@endphp
@extends('storefront.layout', ['title' => 'Trade-in — '.Site::name().', '.Site::city(), 'description' => Site::t('Сдайте старый телефон в зачёт'), 'tab' => 'tradein'])
@section('content')
<div class="max-w-3xl mx-auto px-4 py-5 space-y-4">
    <div class="rounded-3xl bg-foreground text-white p-6">
        <h1 class="text-[30px] font-extrabold uppercase leading-none">Trade-in</h1>
        <p class="mt-2 text-white/80">{{ Site::t('Сдайте старый телефон в зачёт') }}</p>
    </div>
    @if ($page && ($page->body_ru || $page->body_kk))
        <article class="prose-site bg-white rounded-2xl border border-border p-5 text-[15px]">{!! App\Domain\Storefront\Services\SitePages::render(Site::$lang === 'kk' && $page->body_kk ? $page->body_kk : $page->body_ru) !!}</article>
    @endif
    <form method="get" action="{{ Site::route('wa') }}" class="bg-white rounded-2xl border border-border p-5 space-y-4">
        <input type="hidden" name="t" value="tradein">
        @if ($want)<input type="hidden" name="want" value="{{ $want }}">@endif
        <p class="text-[18px] font-bold">1. {{ Site::t('Что сдаёте?') }}</p>
        <label class="block text-[14px] font-medium">{{ Site::t('Модель') }}
            <input name="model" list="tm" required maxlength="60" class="mt-1 w-full rounded-xl border-input h-11" placeholder="iPhone 13">
            <datalist id="tm">@foreach ($models as $m)<option value="{{ $m }}">@endforeach</datalist></label>
        <div><p class="text-[14px] font-medium mb-1.5">{{ Site::t('Память') }}</p><div class="flex flex-wrap gap-2">{!! $opt('memory', ['64 ГБ', '128 ГБ', '256 ГБ', '512 ГБ', '1 ТБ']) !!}</div></div>
        <p class="text-[18px] font-bold pt-2">2. {{ Site::t('Состояние') }}</p>
        <div><p class="text-[14px] font-medium mb-1.5">{{ Site::t('Экран') }}</p><div class="flex flex-wrap gap-2">{!! $opt('screen', [Site::t('целый'), Site::t('царапины'), Site::t('трещина')]) !!}</div></div>
        <div><p class="text-[14px] font-medium mb-1.5">{{ Site::t('Корпус') }}</p><div class="flex flex-wrap gap-2">{!! $opt('body', [Site::t('как новый'), Site::t('следы'), Site::t('сколы')]) !!}</div></div>
        <div><p class="text-[14px] font-medium mb-1.5">{{ Site::t('Батарея') }}</p><div class="flex flex-wrap gap-2">{!! $opt('battery', ['≥ 90%', '80–89%', '< 80%', Site::t('не знаю')]) !!}</div></div>
        <div><p class="text-[14px] font-medium mb-1.5">{{ Site::t('Работает Face ID / Touch ID') }}</p><div class="flex flex-wrap gap-2">{!! $opt('faceid', [Site::t('да'), Site::t('нет')]) !!}</div></div>
        <div><p class="text-[14px] font-medium mb-1.5">{{ Site::t('Был ремонт') }}</p><div class="flex flex-wrap gap-2">{!! $opt('repair', [Site::t('да'), Site::t('нет'), Site::t('не знаю')]) !!}</div></div>
        <div><p class="text-[14px] font-medium mb-1.5">{{ Site::t('Есть коробка') }}</p><div class="flex flex-wrap gap-2">{!! $opt('box', [Site::t('да'), Site::t('нет')]) !!}</div></div>
        <p class="text-[18px] font-bold pt-2">3. {{ Site::t('Предварительная оценка') }}</p>
        <p class="text-[15px]">{{ Site::t('Оценим в WhatsApp за 5 минут') }}. <span class="text-muted-foreground">{{ Site::t('Точная цена — после осмотра в магазине') }}</span></p>
        @if ($want)<p class="text-[14px] text-muted-foreground">{{ Site::t('Взамен') }}: <b class="font-mono">{{ $want }}</b></p>@endif
        <button class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-wa text-white font-semibold h-12">@include('storefront.partials.wa-icon', ['class' => 'w-5 h-5']){{ Site::t('Отправить в WhatsApp') }}</button>
        <p class="text-[13px] text-muted-foreground">{{ Site::t('Что взять с собой: удостоверение личности, телефон с выключенным iCloud / Google-аккаунтом, зарядку') }}</p>
    </form>
</div>
@endsection
