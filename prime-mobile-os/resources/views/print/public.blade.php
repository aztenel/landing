{{-- Electronic receipt and warranty by a secret link (TZ 06 §6.5): no login, no IMEI in the address, phone masked. --}}
@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
@include('print._head')
<hr>
<div class="c b">{{ $P::t('Электрондық чек', 'Электронный чек') }} № {{ $doc->number }}</div>
<div class="c">{{ $doc->occurred_at->setTimezone($tz)->format('d.m.Y H:i') }}</div>
@if ($doc->status?->value === 'reversed')<div class="dup">{{ __('Чек отменён') }}</div>@endif
<hr>
@foreach ($lines as $x)
    @php($months = $warranty($x->unit, $x->acc))
    <div class="b">{{ $x->unit ? $x->unit->variant?->title() : ($x->acc?->name_ru ?? '') }}</div>
    <div class="row"><span>{{ $x->line->qty }} x {{ $P::money((int) $x->line->price) }}</span><span>{{ $P::money((int) $x->line->amount) }}</span></div>
    @if ($x->unit)<div class="small">{{ $x->unit->public_code }}@if ($x->unit->imei1) · IMEI …{{ substr($x->unit->imei1, -4) }}@endif</div>@endif
    @if ($months > 0)<div class="small b">{{ $P::t('Кепілдік '.$P::warrantyUntil($doc->occurred_at->setTimezone($tz), $months)->format('d.m.Y').' дейін', 'Гарантия до '.$P::warrantyUntil($doc->occurred_at->setTimezone($tz), $months)->format('d.m.Y')) }}</div>@endif
@endforeach
<hr>
<div class="row huge" style="align-items: baseline"><span style="font-size: .7em">{{ $P::t('ЖИЫНЫ', 'ИТОГО') }}</span><span>{{ $P::money((int) $sale->total) }}</span></div>
@foreach ($payments as $p)<div class="row"><span>{{ $p->method->label() }}</span><span>{{ $P::money((int) $p->amount) }}</span></div>@endforeach
<hr>
<div class="c small">ФИСКАЛДЫҚ ЧЕК ЕМЕС / НЕ ЯВЛЯЕТСЯ ФИСКАЛЬНЫМ ЧЕКОМ</div>
@endsection
