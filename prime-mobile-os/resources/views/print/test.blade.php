@extends('print.layout')
@php($P = \App\Domain\Printing\Printing::class)
@section('body')
<div class="{{ str_starts_with($size, 'label') ? 'label' : '' }}" style="border: 1px solid #000; box-sizing: border-box; padding: 1mm">
    <div class="b">{{ __('Тестовая печать') }} · {{ $size }}</div>
    <div style="display: flex; height: 3mm">@for ($i = 0; $i < 10; $i++)<div style="width: 5mm; border-left: 1px solid #000; font-size: 6px">{{ $i * 5 }}</div>@endfor</div>
    {!! $P::code128('356939234474590') !!}
    <div class="qr" style="width: 12mm">{!! $P::qr('https://example.kz/PM-10001') !!}</div>
    <div class="small">Қазақша: ә ғ қ ң ө ұ ү һ і · 489 000 ₸</div>
</div>
<p class="small">{{ __('Если рамка обрезана — в окне печати: поля «Нет», масштаб 100 %, размер бумаги как в шаблоне.') }}</p>
@endsection
