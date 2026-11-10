@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@php($small = $size === 'label30x20')
@section('body')
@foreach ($labels as $a)
    <div class="label">
        <div class="b" style="font-size: {{ $small ? '7px' : '8px' }}; line-height: 1.1; max-height: {{ $small ? '3mm' : '6mm' }}; overflow: hidden">{{ $a->name_kk ?: $a->name_ru }}</div>
        @unless ($small)<div style="font-size: 7px; white-space: nowrap; overflow: hidden">{{ $a->name_ru }}</div>@endunless
        <div class="b" style="font-size: {{ $small ? '10px' : '12px' }}">{{ isset($prices[$a->id]) ? $P::money((int) $prices[$a->id]) : '' }}</div>
        {!! $P::ean13($ean($a)) !!}
        <div class="mono" style="font-size: 6px">{{ $ean($a) }}@unless ($small) · {{ $a->sku }}@endunless</div>
    </div>
@endforeach
@endsection
