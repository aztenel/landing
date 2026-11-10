{{-- Print forms Ф1–Ф12 (TZ 06 §6): HTML of the exact size → browser print. Black only, no grey fills; Onest has kk letters and ₸. --}}
@php
    $sizes = [
        'tape58' => ['58mm auto', '48mm', '11px'], 'tape80' => ['80mm auto', '72mm', '12px'],
        'a4' => ['A4', '186mm', '12px'], 'a5' => ['A5', '132mm', '11px'],
        'label58x40' => ['58mm 40mm', '54mm', '9px'], 'label40x30' => ['40mm 30mm', '36mm', '8px'], 'label30x20' => ['30mm 20mm', '27mm', '7px'],
        'sheet' => ['A4', '196mm', '10px'],
    ];
    [$page, $width, $font] = $sizes[$size];
    $tape = str_starts_with($size, 'tape');
    $label = str_starts_with($size, 'label');
@endphp
<!doctype html>
<html lang="{{ \App\Domain\Printing\Printing::lang() === 'kk' ? 'kk' : 'ru' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }}</title>
@vite('resources/css/print.css')
<style>
    @page { size: {{ $page }}; margin: {{ $tape ? '2mm 3mm' : ($label ? '0' : '10mm 12mm') }}; }
    html, body { background: #fff; color: #000; }
    body { font-family: Onest, Arial, sans-serif; font-size: {{ $font }}; line-height: 1.3; margin: 0 auto; width: {{ $width }}; }
    .label { width: {{ $width }}; height: {{ $size === 'label58x40' ? '38mm' : ($size === 'label40x30' ? '28mm' : '19mm') }}; padding: 1mm 2mm; overflow: hidden; page-break-after: always; box-sizing: border-box; }
    .label:last-child { page-break-after: auto; }
    @media screen { body { padding: 12px 0; } .page-tools { position: sticky; top: 0; background: #fff; padding: 8px 0; display: flex; gap: 8px; font-size: 13px; } .label, .tag { outline: 1px dashed #bbb; margin-bottom: 4px; } }
    @media print { .page-tools { display: none !important; } }
</style>
</head>
<body>
    <div class="page-tools"><button type="button" onclick="window.print()">{{ __('Печать') }}</button><span style="color:#555">{{ __('Поля — «Нет», масштаб — 100 %, «Фон» — включить') }}</span></div>
    @if (! empty($duplicate))
        <div class="dup">{{ \App\Domain\Printing\Printing::t('КӨШІРМЕ', 'ДУБЛИКАТ') }} · {{ now()->setTimezone($tz ?? 'Asia/Almaty')->format('d.m.Y H:i') }}</div>
    @endif
    @if (! empty($draft))
        <div class="watermark">{{ \App\Domain\Printing\Printing::t('ЖОБА', 'ПРОЕКТ') }}</div>
    @endif
    @yield('body')
    @if (empty($noAutoPrint))
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
    @endif
</body>
</html>
