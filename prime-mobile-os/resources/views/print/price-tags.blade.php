@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
@if ($noKk->isNotEmpty())
    <div class="dup" style="text-align:left">{{ __('Нет казахского названия у :n товаров — ценники не печатаются. Добавьте перевод в справочнике.', ['n' => $noKk->count()]) }} {{ $noKk->pluck('public_code')->implode(', ') }}</div>
@endif
<div class="sheet">
    @foreach ($units as $u)
        @php($v = $u->variant)
        @php($price = $retail($u))
        @php($used = $u->condition?->value === 'used')
        <div class="tag">
            <div class="b" style="font-size: 12px; line-height: 1.15">{{ trim(($v?->productModel?->name_kk ?: $v?->productModel?->name_ru).' '.$v?->memory?->name.', '.$v?->color?->name_kk) }}</div>
            <div style="font-size: 10px">{{ trim($v?->productModel?->name_ru.' '.$v?->memory?->name.', '.$v?->color?->name_ru) }}</div>
            <div class="small" style="margin-top: 1mm">{{ $used ? 'Қолданылған / Б/у · '.$u->grade.' '.$grade($u->grade, 'kk').' / '.$grade($u->grade, 'ru').($u->battery_percent ? ' · Батарея '.$u->battery_percent.' %' : '') : 'Жаңа / Новый' }}</div>
            <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: flex-end; gap: 2mm">
                <div>
                    <div class="small">Бағасы / Цена</div>
                    <div class="b" style="font-size: 20px; line-height: 1">{{ $price ? $P::money((int) $price) : '—' }}</div>
                    @if ($u->compare_at_price && $price && $u->compare_at_price > $price)<div class="small"><s>{{ $P::money((int) $u->compare_at_price) }}</s></div>@endif
                    @if ($price && ($i = $installment(intdiv((int) $price, 100))))<div class="small">Бөліп төлеу / Рассрочка: {{ number_format($i[0], 0, '', ' ') }} ₸ × {{ $i[1] }} ай / мес</div>@endif
                    <div style="font-size: 7px; margin-top: 1mm">{{ $brand }} · {{ now()->setTimezone($tz)->format('d.m.Y') }}</div>
                </div>
                @if (isset($listed[$u->public_code]))
                    <div style="width: 15mm; text-align: center"><div class="qr">{!! $P::qr($shop.'/'.$u->public_code) !!}</div><div style="font-size: 7px">{{ $u->public_code }}</div></div>
                @else
                    <div style="font-size: 8px">{{ $u->public_code }}</div>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endsection
