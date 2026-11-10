<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $doc->number }}</title>
<style>body{font:13px/1.45 Arial,sans-serif;max-width:720px;margin:24px auto;color:#000}h1{font-size:18px;text-align:center}table{width:100%;border-collapse:collapse}td{border:1px solid #999;padding:4px 6px;vertical-align:top}.r{text-align:right}.sign{margin-top:36px;display:flex;justify-content:space-between}@media print{.noprint{display:none}}</style></head>
<body>
<h1>{{ __('Акт приёма б/у телефона') }} {{ $doc->number }}</h1>
<p class="r">{{ $doc->posted_at?->setTimezone($org->timezone)->format('d.m.Y H:i') }}</p>
<p>{{ $org->legal_name ?: $org->name }}{{ $org->bin_iin ? ', БИН/ИИН '.$org->bin_iin : '' }} {{ __('принял(а) у') }}:</p>
<table>
    <tr><td>{{ __('ФИО') }}</td><td>{{ $customer?->name }}</td></tr>
    <tr><td>{{ __('ИИН') }}</td><td>{{ $iin }}</td></tr>
    <tr><td>{{ __('№ удостоверения') }}</td><td>{{ $docNo }}</td></tr>
    <tr><td>{{ __('Телефон') }}</td><td>{{ $customer?->phone }}</td></tr>
</table>
<p>{{ __('Устройство') }}:</p>
<table>
    <tr><td>{{ __('Модель') }}</td><td>{{ $variant?->title() }}</td></tr>
    <tr><td>IMEI</td><td>{{ $act->imei1 }}{{ $act->imei2 ? ' / '.$act->imei2 : '' }}</td></tr>
    <tr><td>{{ __('Состояние') }}</td><td>{{ __('грейд') }} {{ $act->grade }}{{ $act->battery_percent ? ', '.__('батарея').' '.$act->battery_percent.'%' : '' }}</td></tr>
    <tr><td>{{ __('Чек-лист') }}</td><td>{{ collect($act->checklist)->map(fn ($v, $k) => (\App\Filament\TradeIn\Pages\NewAct::CHECKS[$k] ?? $k).': '.['yes' => 'да', 'no' => 'нет', 'skip' => 'не проверял'][$v] ?? $v)->implode('; ') }}</td></tr>
</table>
<p><b>{{ __('Сумма') }}: {{ \App\Support\Money\Money::kztMinor((int) $act->amount)->format() }}</b>
    @if ($act->offset_amount) — {{ __('в зачёт покупки') }} {{ \App\Support\Money\Money::kztMinor((int) $act->offset_amount)->format() }}@endif
    @foreach ($payouts as $p); {{ $p->method === 'cash' ? __('наличными') : __('переводом') }} {{ \App\Support\Money\Money::kztMinor((int) $p->amount)->format() }}@endforeach</p>
<p>{{ __('Сдающий подтверждает, что является собственником устройства, устройство не в залоге и не в розыске.') }}</p>
<div class="sign"><span>{{ __('Сдал') }}: ____________</span><span>{{ __('Принял') }}: ____________</span></div>
<p class="noprint"><button onclick="window.print()">{{ __('Печать') }}</button></p>
</body></html>
