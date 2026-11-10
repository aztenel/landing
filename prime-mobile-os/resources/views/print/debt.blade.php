@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
<div class="row"><div><b>{{ $brand }}</b><div class="small">{{ $org->legal_name }}{{ $org->bin_iin ? ', БИН/ИИН '.$org->bin_iin : '' }}</div></div><div class="small">{{ $store?->name }}</div></div>
<h1>{{ $P::t('Қарыз туралы түбіртек', 'Квитанция о долге') }}</h1>
<p class="c">{{ $P::t('Чек', 'Чек') }} № {{ $doc->number }} · {{ $doc->occurred_at->setTimezone($tz)->format('d.m.Y H:i') }}</p>
<table class="grid">
    <tr><th style="width: 35%">{{ $P::t('Клиент', 'Клиент') }}</th><td>{{ $customer?->name }}, {{ $customer?->phone }}</td></tr>
    @foreach ($lines as $x)<tr><th>{{ $P::t('Тауар', 'Товар') }}</th><td>{{ $x->unit ? $x->unit->variant?->title().' · IMEI '.$x->unit->imei1 : $x->acc?->name_ru }} — {{ $P::money((int) $x->line->amount) }}</td></tr>@endforeach
    <tr><th>{{ $P::t('Сатып алу сомасы', 'Сумма покупки') }}</th><td>{{ $P::money((int) $sale->total) }}</td></tr>
    <tr><th>{{ $P::t('Қарыз сомасы', 'Сумма долга') }}</th><td class="big">{{ $P::money((int) $debt->amount) }} ({{ $P::words(intdiv((int) $debt->amount, 100)) }})</td></tr>
    <tr><th>{{ $P::t('Қайтару мерзімі', 'Вернуть до') }}</th><td class="big">{{ $debt->debt_due_date?->format('d.m.Y') }}</td></tr>
</table>
<p class="small">{{ __('Я, :name, обязуюсь погасить долг в указанный срок. (Текст обязательства проверяет юрист; это не договор займа.)', ['name' => $customer?->name]) }}</p>
<div class="sign"><div>{{ $P::t('Клиент', 'Клиент') }}</div><div>{{ $P::t('Сатушы', 'Продавец') }}: {{ $seller?->name }}</div></div>
@endsection
