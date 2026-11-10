@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
@include('print._head')
<hr>
<div class="c b">ҚАЙТАРУ ЧЕГІ / ЧЕК ВОЗВРАТА</div>
<div class="c small">ФИСКАЛДЫҚ ЧЕК ЕМЕС / НЕ ЯВЛЯЕТСЯ ФИСКАЛЬНЫМ ЧЕКОМ</div>
<hr>
<div>№ {{ $doc->number }} · {{ $doc->occurred_at->setTimezone($tz)->format('d.m.Y H:i') }}</div>
@if ($saleDoc)<div>{{ $P::t('Чек бойынша', 'По чеку') }} № {{ $saleDoc->number }} {{ $P::t('', 'от') }} {{ $saleDoc->occurred_at->setTimezone($tz)->format('d.m.Y') }}</div>@endif
<hr>
@foreach ($lines as $x)
    <div class="b">{{ $x->unit ? $x->unit->variant?->title() : $x->acc }}</div>
    @if ($x->unit?->imei1)<div class="small mono">IMEI: {{ $x->unit->imei1 }}</div>@endif
    <div class="row"><span>{{ $x->line->qty }} {{ $P::t('дана', 'шт.') }}</span><span>{{ $P::money((int) $x->line->amount) }}</span></div>
@endforeach
@if ($doc->reason_text || $doc->comment)<div class="small">{{ $P::t('Себебі', 'Причина') }}: {{ $doc->reason_text ?: $doc->comment }}</div>@endif
<hr>
<div class="row huge"><span>{{ $P::t('ҚАЙТАРЫЛДЫ', 'К ВЫДАЧЕ') }}</span><span>{{ $P::money((int) $ret->total) }}</span></div>
@foreach ($payments as $p)<div class="row"><span>{{ \App\Domain\Reports\Services\Metrics::methodLabel((string) ($p->method?->value ?? $p->method)) }}</span><span>{{ $P::money((int) $p->amount) }}</span></div>@endforeach
@if ($ret->bonus_cancelled)<div class="small">{{ $P::t('Бонустар жойылды', 'Бонусы отменены') }}: {{ $P::money((int) $ret->bonus_cancelled) }}</div>@endif
<div class="sign"><div>{{ $P::t('Клиент ақша алды', 'Клиент получил деньги') }}</div><div>{{ $P::t('Сатушы', 'Продавец') }}</div></div>
@endsection
