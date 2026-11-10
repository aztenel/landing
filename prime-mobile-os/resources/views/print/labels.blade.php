@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
@foreach ($units as $u)
    @php($v = $u->variant)
    @php($code = $u->imei1 ?: ($u->serial ?: $u->public_code))
    <div class="label">
        <div class="row b" style="font-size: 9px"><span style="white-space: nowrap; overflow: hidden">{{ trim($v?->productModel?->name_ru.' '.$v?->memory?->name.' '.$v?->color?->name_ru) }}</span><span>{{ $u->public_code }}</span></div>
        <div class="row" style="font-size: 8px"><span>{{ $u->condition?->value === 'used' ? 'Б/у · '.$u->grade.($u->battery_percent ? ' · АКБ '.$u->battery_percent.' %' : '') : 'Жаңа / Новый' }}</span>
            <span class="b">{{ $withPrice && ($p = $retail($u)) ? $P::money((int) $p) : '' }}</span></div>
        <div style="display: flex; gap: 1.5mm; align-items: center; margin-top: .8mm">
            <div style="flex: 1; min-width: 0">{!! $P::code128($code) !!}<div class="mono" style="font-size: 8px">{{ $u->imei1 ? 'IMEI '.$u->imei1 : $code }}</div></div>
            @if (isset($listed[$u->public_code]))<div class="qr" style="width: 12mm">{!! $P::qr($shop.'/'.$u->public_code) !!}</div>@endif
        </div>
        <div style="font-size: 7px">{{ $brand }} · {{ ($u->received_at ?? now())->setTimezone($tz)->format('d.m.Y') }}</div>
    </div>
@endforeach
@endsection
