@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
@for ($copy = 0; $copy < 2; $copy++)
<div style="{{ $copy === 0 ? 'page-break-after: always' : '' }}">
    @include('print._head')
    <hr>
    <div class="c b">{{ $P::t('Кепілдікке қабылдау түбіртегі', 'Квитанция о приёме на гарантию') }}</div>
    <div class="c">№ {{ $case->number }} · {{ $case->created_at->setTimezone($tz)->format('d.m.Y H:i') }}</div>
    <hr>
    @if ($customer)<div>{{ $P::t('Клиент', 'Клиент') }}: {{ $customer->name }} {{ $P::maskPhone($customer->phone) }}</div>@endif
    @if ($unit)<div class="b">{{ $unit->variant?->title() }}</div><div class="mono small">IMEI: {{ $unit->imei1 }} · {{ $unit->public_code }}</div>@endif
    @if ($saleDoc)<div class="small">{{ $P::t('Сатылды', 'Продан') }} {{ $saleDoc->occurred_at->setTimezone($tz)->format('d.m.Y') }}, № {{ $saleDoc->number }}@if ($until) · {{ $P::t('кепілдік', 'гарантия до') }} {{ $until->format('d.m.Y') }}@endif</div>@endif
    <div>{{ $P::t('Шағым', 'Жалоба') }}: {{ $case->complaint }}</div>
    @if ($case->kit)<div class="small">{{ $P::t('Жиынтық', 'Комплект') }}: {{ $case->kit }}</div>@endif
    <div class="small">{{ __('Пароль не храним — устройство принято без пароля / клиент вышел из iCloud.') }}</div>
    <div class="small">{{ __('Срок: до 10 дней на ремонт, до 30 дней на решение (сроки проверяет юрист).') }}</div>
    <div class="sign"><div>{{ $P::t('Тапсырды', 'Сдал') }}</div><div>{{ $P::t('Қабылдады', 'Принял') }}</div></div>
    <div class="c small" style="margin-top: 4px">{{ $copy === 0 ? __('экземпляр клиента') : __('экземпляр магазина') }}</div>
</div>
@endfor
@endsection
