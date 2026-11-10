@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
<h1>{{ $P::t('Өзара есеп айырысуды салыстыру актісі', 'Акт сверки взаиморасчётов') }}</h1>
<p class="c">{{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }}</p>
<p>{{ $org->legal_name ?: $org->name }}{{ $org->bin_iin ? ', БИН/ИИН '.$org->bin_iin : '' }} {{ __('и') }} {{ $party->name }}{{ $type === 'customer' && $party->phone ? ', '.$party->phone : '' }}</p>
@foreach ($blocks as $b)
    @php($fmt = fn ($v) => $b['currency'] === 'USD' ? '$'.number_format($v / 100, 2, ',', ' ') : $P::money((int) $v))
    <h2>{{ $b['currency'] }}</h2>
    <table class="grid">
        <tr><th>{{ $P::t('Күні', 'Дата') }}</th><th>{{ $P::t('Құжат', 'Документ') }}</th><th class="n">{{ __('Долг увеличился') }}</th><th class="n">{{ __('Долг уменьшился') }}</th></tr>
        <tr class="b"><td colspan="2">{{ $P::t('Бастапқы қалдық', 'Остаток на начало') }}</td><td class="n" colspan="2">{{ $fmt($b['start']) }}</td></tr>
        @foreach ($b['rows'] as $r)
            <tr><td>{{ \Carbon\Carbon::parse($r->occurred_at)->setTimezone($tz)->format('d.m.Y') }}</td><td>{{ ($types[$r->type] ?? '').' '.$r->number }}{{ $r->comment ? ' · '.$r->comment : '' }}</td>
                <td class="n">{{ $r->amount > 0 ? $fmt($r->amount) : '' }}</td><td class="n">{{ $r->amount < 0 ? $fmt(-$r->amount) : '' }}</td></tr>
        @endforeach
        <tr class="b"><td colspan="2">{{ $P::t('Соңғы қалдық', 'Остаток на конец') }}</td><td class="n" colspan="2">{{ $fmt($b['end']) }}</td></tr>
    </table>
@endforeach
<p class="small">{{ __('Положительный остаток — :p должен фирме; отрицательный — фирма должна (аванс).', ['p' => $type === 'customer' ? __('клиент') : __('поставщик')]) }}</p>
<div class="sign"><div>{{ $org->legal_name ?: $org->name }} · М.П.</div><div>{{ $party->name }}</div></div>
@endsection
