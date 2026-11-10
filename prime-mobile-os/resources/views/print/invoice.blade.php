@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
<div class="row"><div class="small">{{ $org->legal_name ?: $org->name }}{{ $org->bin_iin ? ', БИН/ИИН '.$org->bin_iin : '' }}</div><div class="small">{{ $doc->occurred_at->setTimezone($tz)->format('d.m.Y') }}</div></div>
<h1>{{ $P::t($heading[0], $heading[1]) }} № {{ $doc->number }}</h1>
<table class="grid" style="margin-bottom: 8px">
    <tr><th style="width: 30%">{{ $P::t('Жіберуші', 'Отправитель') }}</th><td>{{ $from }}</td></tr>
    <tr><th>{{ $P::t('Алушы', 'Получатель') }}</th><td>{{ $to }}</td></tr>
    @if ($basis)<tr><th>{{ $P::t('Негіздеме', 'Основание') }}</th><td>{{ $basis }}</td></tr>@endif
    @if ($prices && $currency === 'USD')<tr><th>{{ $P::t('Валюта', 'Валюта') }}</th><td>USD @if ($doc->exchange_rate) · {{ $P::t('бағам', 'курс') }} {{ (float) $doc->exchange_rate }} ₸@endif</td></tr>@endif
</table>
<table class="grid">
    <tr><th>№</th><th>{{ $P::t('Атауы', 'Наименование') }}</th><th>IMEI / {{ $P::t('штрихкод', 'штрихкод') }}</th><th>{{ $P::t('Өлш.', 'Ед.') }}</th><th class="n">{{ $P::t('Саны', 'Кол-во') }}</th>
        @if ($prices)<th class="n">{{ $P::t('Бағасы', 'Цена') }}</th><th class="n">{{ $P::t('Сомасы', 'Сумма') }}</th>@endif</tr>
    @foreach ($rows as $i => $r)
        <tr><td>{{ $i + 1 }}</td><td>{{ $r['name'] }}</td><td class="mono small">{{ $r['code'] }}</td><td>{{ $P::t('дана', 'шт.') }}</td><td class="n">{{ $r['qty'] }}</td>
            @if ($prices)<td class="n">{{ $currency === 'USD' ? '$'.number_format($r['price'] / 100, 2, ',', ' ') : $P::money($r['price']) }}</td><td class="n">{{ $currency === 'USD' ? '$'.number_format($r['sum'] / 100, 2, ',', ' ') : $P::money($r['sum']) }}</td>@endif</tr>
    @endforeach
    <tr class="b"><td colspan="4">{{ $P::t('Барлығы', 'Итого') }}</td><td class="n">{{ $rows->sum('qty') }}</td>@if ($prices)<td></td><td class="n">{{ $currency === 'USD' ? '$'.number_format($rows->sum('sum') / 100, 2, ',', ' ') : $P::money((int) $rows->sum('sum')) }}</td>@endif</tr>
</table>
@if ($prices && $currency === 'KZT')<p class="small">{{ $P::t('Жазбаша', 'Прописью') }}: {{ $P::words(intdiv((int) $rows->sum('sum'), 100)) }}</p>@endif
<div class="sign"><div>{{ $P::t('Тапсырды', 'Сдал') }} (ФИО, {{ $P::t('күні', 'дата') }})</div><div>{{ $P::t('Қабылдады', 'Принял') }} (ФИО, {{ $P::t('күні', 'дата') }})</div><div>М.О. / М.П.</div></div>
@endsection
