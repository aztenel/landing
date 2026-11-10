@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
@include('print._head')
<hr>
<div class="c b">ФИСКАЛДЫҚ ЧЕК ЕМЕС</div>
<div class="c b">НЕ ЯВЛЯЕТСЯ ФИСКАЛЬНЫМ ЧЕКОМ</div>
<hr>
<div>{{ $P::t('Чек', 'Чек') }} № {{ $doc->number }}</div>
<div>{{ $doc->occurred_at->setTimezone($tz)->format('d.m.Y H:i') }}</div>
<div>{{ $P::t('Сатушы', 'Продавец') }}: {{ $seller ? preg_replace('/^(\S+)\s+(\S).*$/u', '$1 $2.', $seller->name) : '' }}</div>
@if ($customer)<div>{{ $P::t('Клиент', 'Клиент') }}: {{ $customer->name }} {{ $P::maskPhone($customer->phone) }}</div>@endif
<hr>
@foreach ($lines as $x)
    @php($l = $x->line)
    @php($months = $warranty($x->unit, $x->acc))
    <div class="b">{{ $x->unit ? $x->unit->variant?->title() : ($x->acc?->name_ru ?? '') }}</div>
    @if ($x->acc && $x->acc->name_kk && $P::lang() !== 'ru')<div class="small">{{ $x->acc->name_kk }}</div>@endif
    <div class="row"><span>{{ $l->qty }} x {{ $P::money((int) $l->price) }}</span><span>{{ $P::money((int) $l->amount) }}</span></div>
    @if ($l->line_discount || $l->check_discount_share)<div class="row small"><span>{{ $P::t('Жеңілдік', 'Скидка') }}</span><span>−{{ $P::money((int) $l->line_discount + (int) $l->check_discount_share) }}</span></div>@endif
    @if ($x->unit)
        @if ($x->unit->imei1)<div class="small mono">IMEI 1: {{ $x->unit->imei1 }}</div>@endif
        @if ($x->unit->imei2)<div class="small mono">IMEI 2: {{ $x->unit->imei2 }}</div>@endif
        <div class="small">{{ $P::t('Код', 'Код') }}: {{ $x->unit->public_code }}@if ($x->unit->condition?->value === 'used') · {{ $P::t('Қолданылған', 'Б/у') }}, {{ $P::t('грейд', 'грейд') }} {{ $x->unit->grade }}@endif</div>
    @endif
    @if ($months > 0)<div class="small">{{ $P::t('Кепілдік '.$P::warrantyUntil($doc->occurred_at->setTimezone($tz), $months)->format('d.m.Y').' дейін', 'Гарантия до '.$P::warrantyUntil($doc->occurred_at->setTimezone($tz), $months)->format('d.m.Y')) }}</div>@endif
@endforeach
<hr>
@if ($sale->check_discount)<div class="row"><span>{{ $P::t('Чекке жеңілдік', 'Скидка на чек') }}</span><span>−{{ $P::money((int) $sale->check_discount) }}</span></div>@endif
<div class="row huge" style="align-items: baseline"><span style="font-size: .7em">{{ $P::t('ЖИЫНЫ', 'ИТОГО') }}</span><span>{{ $P::money((int) $sale->total) }}</span></div>
@foreach ($payments as $p)
    <div class="row"><span>{{ $p->method->label() }}</span><span>{{ $P::money((int) $p->amount) }}</span></div>
    @if ($p->tendered && $p->change)<div class="row small"><span>{{ $P::t('Берілді', 'Внесено') }} {{ $P::money((int) $p->tendered) }}</span><span>{{ $P::t('Қайтарым', 'Сдача') }} {{ $P::money((int) $p->change) }}</span></div>@endif
    @if ($p->method->value === 'debt')<div class="small">{{ $P::t('Қарыз', 'В долг') }}: {{ $P::money((int) $p->amount) }}, {{ $P::t('қайтару', 'вернуть до') }} {{ $p->debt_due_date?->format('d.m.Y') }}</div>@endif
@endforeach
@if ($bonus && ($bonus->plus || $bonus->minus))
    <hr>
    <div class="small">{{ $P::t('Бонустар', 'Бонусы') }}: +{{ $P::money((int) $bonus->plus) }}@if ($bonus->av) ({{ $P::t('бастап', 'доступны с') }} {{ \Carbon\Carbon::parse($bonus->av)->setTimezone($tz)->format('d.m.Y') }})@endif@if ($bonus->minus), −{{ $P::money((int) $bonus->minus) }}@endif · {{ $P::t('баланс', 'баланс') }} {{ $P::money((int) $bonusBalance) }}</div>
@endif
@if ($doc->fiscal_receipt_no)<div class="small">{{ $P::t('Фискалдық чек', 'Фискальный чек') }} № {{ $doc->fiscal_receipt_no }}</div>@endif
<hr>
<div class="qr" style="width: 22mm; margin: 0 auto">{!! $P::qr($link) !!}</div>
<div class="c small">{{ $P::t('Электрондық чек және кепілдік', 'Электронный чек и гарантия') }}</div>
<hr>
<div class="c">Сатып алғаныңызға рахмет!</div>
<div class="c">Спасибо за покупку!</div>
@if ($org->settings()->get('texts.receipt_footer'))<div class="c small">{{ $org->settings()->get('texts.receipt_footer') }}</div>@endif
@endsection
