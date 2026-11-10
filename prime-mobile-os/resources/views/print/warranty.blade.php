@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
@foreach ($lines as $x)
    @php($months = $warranty($x->unit, $x->acc))
    @php($until = $P::warrantyUntil($doc->occurred_at->setTimezone($tz), $months))
    <div style="{{ ! $loop->last ? 'page-break-after: always' : '' }}">
        <div class="row" style="align-items: flex-start">
            <div><div class="b big">{{ $brand }}</div>@if ($store)<div class="small">{{ $store->name }}{{ $store->address ? ', '.$store->address : '' }}{{ ($store->meta['whatsapp'] ?? $store->phone) ? ' · '.($store->meta['whatsapp'] ?? $store->phone) : '' }}</div>@endif
                @if ($org->legal_name)<div class="small">{{ $org->legal_name }}{{ $org->bin_iin ? ', БИН/ИИН '.$org->bin_iin : '' }}</div>@endif</div>
            <div class="qr" style="width: 20mm">{!! $P::qr($link) !!}</div>
        </div>
        <h1>КЕПІЛДІК ТАЛОНЫ / ГАРАНТИЙНЫЙ ТАЛОН № {{ $doc->number }}</h1>
        <table class="grid">
            <tr><th style="width: 38%">{{ $P::t('Тауар', 'Товар') }}</th><td class="b">{{ $x->unit ? $x->unit->variant?->title() : $x->acc?->name_ru }}</td></tr>
            @if ($x->unit)
                <tr><th>{{ $P::t('Жағдайы', 'Состояние') }}</th><td>{{ $x->unit->condition?->value === 'used' ? $P::t('Қолданылған', 'Б/у').', '.$P::t('грейд', 'грейд').' '.$x->unit->grade : $P::t('Жаңа', 'Новый') }}</td></tr>
                <tr><th>IMEI 1 / IMEI 2</th><td class="mono">{{ $x->unit->imei1 }}{{ $x->unit->imei2 ? ' / '.$x->unit->imei2 : '' }}</td></tr>
                @if ($x->unit->serial)<tr><th>{{ $P::t('Сериялық нөмір', 'Серийный номер') }}</th><td class="mono">{{ $x->unit->serial }}</td></tr>@endif
                <tr><th>{{ $P::t('Код', 'Код') }}</th><td>{{ $x->unit->public_code }}</td></tr>
            @endif
            <tr><th>{{ $P::t('Сатылған күні', 'Дата продажи') }}</th><td>{{ $doc->occurred_at->setTimezone($tz)->format('d.m.Y') }}, {{ $P::t('чек', 'чек') }} № {{ $doc->number }}</td></tr>
            <tr><th>{{ $P::t('Бағасы', 'Цена') }}</th><td>{{ $P::money((int) $x->line->amount) }}</td></tr>
            <tr><th>{{ $P::t('Сатушы', 'Продавец') }}</th><td>{{ $seller?->name }}</td></tr>
            @if ($customer)<tr><th>{{ $P::t('Сатып алушы', 'Покупатель') }}</th><td>{{ $customer->name }} {{ $P::maskPhone($customer->phone) }}</td></tr>@endif
            <tr><th>{{ $P::t('Кепілдік мерзімі', 'Срок гарантии') }}</th><td class="big">{{ $P::t($months.' ай, '.$until->format('d.m.Y').' дейін', $months.' мес., до '.$until->format('d.m.Y')) }}</td></tr>
        </table>
        <h2>{{ $P::t('Кепілдік шарттары', 'Условия гарантии') }}</h2>
        <div class="small">{!! nl2br(e($org->settings()->get('texts.warranty_'.($P::lang() === 'kk' ? 'kk' : 'ru')) ?: __('Гарантия действует при наличии этого талона и чека. Гарантия не распространяется на механические повреждения, следы влаги, вскрытие вне сервиса, программные изменения. Условия — по закону РК «О защите прав потребителей» (текст проверяет юрист).'))) !!}</div>
        <h2>{{ $P::t('Жөндеу белгілері', 'Отметки о ремонтах') }}</h2>
        <table class="grid small">
            <tr><th>{{ $P::t('Қабылданды', 'Дата приёма') }}</th><th>№</th><th>{{ $P::t('Жұмыс', 'Работа') }}</th><th>{{ $P::t('Берілді', 'Дата выдачи') }}</th><th>{{ $P::t('Қолы', 'Подпись') }}</th></tr>
            @for ($i = 0; $i < 3; $i++)<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>@endfor
        </table>
        <div class="sign"><div>{{ $P::t('Тауарды алдым, шарттармен таныстым', 'Товар получил, с условиями ознакомлен') }}</div><div>{{ $P::t('Сатушы', 'Продавец') }} · М.О. / М.П.</div></div>
    </div>
@endforeach
@endsection
