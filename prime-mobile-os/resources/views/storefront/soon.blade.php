@php use App\Http\Storefront\Site; $s = Site::store(); @endphp
<!doctype html>
<html lang="{{ Site::$lang }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{{ Site::name() }}</title>@vite('resources/css/storefront.css')</head>
<body class="min-h-screen grid place-items-center bg-[#F6F7F9] px-4">
<div class="bg-white rounded-3xl border border-border p-8 max-w-md w-full text-center space-y-3">
    <x-ui::logo size="lg" class="justify-center" />
    <p class="text-[20px] font-bold pt-2">{{ Site::t('Сайт скоро откроется') }}</p>
    @if ($s)
        <p class="text-[15px]">{{ $s->address_ru }}</p>
        @if ($s->hours)<p class="text-[15px] text-muted-foreground">{{ $s->hours }}</p>@endif
        @if ($s->whatsapp)<a href="{{ Site::wa($s->whatsapp) }}" class="inline-flex items-center gap-2 rounded-xl bg-wa text-white font-semibold px-5 h-12">WhatsApp</a>@endif
    @endif
</div>
</body></html>
