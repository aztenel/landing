@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
@include('print._head')
<hr>
<div class="c b">АУЫСЫМ ҚОРЫТЫНДЫСЫ / ИТОГ СМЕНЫ</div>
<div class="c small">ФИСКАЛДЫҚ ЕМЕС / НЕФИСКАЛЬНЫЙ</div>
<div>№ {{ $shift->number }}</div>
<div class="small">{{ $P::t('Ашты', 'Открыл') }}: {{ $names[$shift->opened_by] ?? '' }} {{ \Carbon\Carbon::parse($shift->opened_at)->setTimezone($tz)->format('d.m H:i') }}</div>
@if ($shift->closed_at)<div class="small">{{ $P::t('Жапты', 'Закрыл') }}: {{ $names[$shift->closed_by] ?? '' }} {{ \Carbon\Carbon::parse($shift->closed_at)->setTimezone($tz)->format('d.m H:i') }}</div>@endif
<hr>
<div class="row"><span>{{ $P::t('Чектер', 'Чеков') }}</span><span>{{ $t['checks'] }}</span></div>
<div class="row"><span>{{ $P::t('Сатылым', 'Продажи') }}</span><span>{{ $P::money($t['sales']) }}</span></div>
<div class="row"><span>{{ $P::t('Қайтару', 'Возвраты') }}</span><span>{{ $P::money($t['returns']) }}</span></div>
<div class="row b"><span>{{ $P::t('Түсім', 'Выручка') }}</span><span>{{ $P::money($t['revenue']) }}</span></div>
<hr>
@foreach ($payments as $method => $row)<div class="row"><span>{{ \App\Domain\Reports\Services\Metrics::methodLabel($method) }}</span><span>{{ $P::money($row['amount']) }}</span></div>@endforeach
@if ($expenses)<div class="row"><span>{{ $P::t('Шығыстар', 'Расходы') }}</span><span>−{{ $P::money($expenses) }}</span></div>@endif
<hr>
@foreach ($counts as $c)
    <div class="b small">{{ $c->name }} · {{ $c->phase === 'open' ? $P::t('ашылу', 'открытие') : $P::t('жабылу', 'закрытие') }}</div>
    <div class="row small"><span>{{ $P::t('Есеп бойынша', 'По учёту') }}</span><span>{{ $P::money((int) $c->expected) }}</span></div>
    <div class="row small"><span>{{ $P::t('Саналды', 'Посчитано') }}</span><span>{{ $P::money((int) $c->counted) }}</span></div>
    <div class="row small b"><span>{{ $P::t('Айырма', 'Разница') }}</span><span>{{ $P::money((int) $c->diff) }}</span></div>
@endforeach
<div class="sign"><div>{{ $P::t('Сатушы', 'Продавец') }}</div><div>{{ $P::t('Кассаны қабылдады', 'Кассу принял') }}</div></div>
@endsection
