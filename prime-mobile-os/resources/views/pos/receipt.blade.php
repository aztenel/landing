<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $doc->number }}</title>
{{-- Temporary receipt 80 mm until the printing module (Ф1) replaces this view; route erp.pos.receipt stays. --}}
<style>
    @page { size: 80mm auto; margin: 3mm; }
    body { font: 12px/1.35 "JetBrains Mono", ui-monospace, monospace; color: #000; background: #fff; margin: 0 auto; width: 74mm; }
    h1 { font-size: 14px; text-align: center; margin: 4px 0; }
    .c { text-align: center; } .r { text-align: right; } .b { font-weight: 700; }
    .row { display: flex; justify-content: space-between; gap: 6px; }
    hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
    .muted { color: #444; font-size: 11px; }
    @media screen { body { padding: 12px; } .noprint { margin-top: 12px; text-align: center; } }
    @media print { .noprint { display: none; } }
</style>
</head>
<body>
    <h1>{{ $org->settings()->get('brand.display_name') ?: $org->name }}</h1>
    <div class="c muted">{{ $org->legal_name }}{{ $org->bin_iin ? ' · БИН/ИИН '.$org->bin_iin : '' }}</div>
    <div class="c muted">{{ $store?->name }}{{ $store?->address ? ', '.$store->address : '' }}</div>
    <hr>
    <div class="row"><span>{{ __('Чек') }} {{ $doc->number }}</span><span>{{ $doc->posted_at?->setTimezone($tz)->format('d.m.Y H:i') }}</span></div>
    <div class="muted">{{ __('Продавец') }}: {{ \App\Models\User::query()->find($sale->seller_id)?->name }}</div>
    <hr>
    @foreach ($lines as $line)
        @php($unit = $line->stock_unit_id ? \App\Domain\Inventory\Models\StockUnit::query()->find($line->stock_unit_id) : null)
        <div>{{ $unit ? $unit->variant?->title() : \App\Domain\Catalog\Models\Accessory::query()->find($line->accessory_id)?->name_ru }}</div>
        @if ($unit && $unit->imei1)<div class="muted">IMEI {{ $unit->imei1 }}</div>@endif
        <div class="row"><span>{{ $line->qty }} × {{ \App\Support\Money\Money::kztMinor((int) $line->price)->format() }}</span><span class="b">{{ \App\Support\Money\Money::kztMinor((int) $line->amount)->format() }}</span></div>
    @endforeach
    <hr>
    @if ($sale->discount_total)<div class="row"><span>{{ __('Скидка') }}</span><span>−{{ \App\Support\Money\Money::kztMinor((int) $sale->discount_total)->format() }}</span></div>@endif
    <div class="row b" style="font-size:14px"><span>{{ __('ИТОГО') }}</span><span>{{ \App\Support\Money\Money::kztMinor((int) $sale->total)->format() }}</span></div>
    @foreach ($payments as $p)
        <div class="row"><span>{{ $p->method->label() }}</span><span>{{ \App\Support\Money\Money::kztMinor((int) $p->amount)->format() }}</span></div>
        @if ($p->change)<div class="row muted"><span>{{ __('Сдача') }}</span><span>{{ \App\Support\Money\Money::kztMinor((int) $p->change)->format() }}</span></div>@endif
    @endforeach
    <hr>
    <div class="c muted">{{ __('Это не фискальный чек.') }}{{ $doc->fiscal_receipt_no ? ' '.__('Фискальный чек').' № '.$doc->fiscal_receipt_no : '' }}</div>
    <div class="c">{{ __('Спасибо за покупку!') }}</div>
    <div class="noprint"><button onclick="window.print()">{{ __('Печать') }}</button></div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
