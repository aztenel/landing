@php use App\Http\Storefront\Site; use App\Support\Ui\Icon; @endphp
<section class="bg-white rounded-2xl border border-border p-5 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-center">
    <div class="min-w-0">
        <h2 class="text-[18px] font-bold">{{ $s->name }}</h2>
        <p class="mt-1 flex items-start gap-1.5 text-[15px]">{!! Icon::svg('pin', 'w-5 h-5 shrink-0 mt-0.5 text-muted-foreground') !!}{{ Site::$lang === 'kk' && $s->address_kk ? $s->address_kk : $s->address_ru }}</p>
        @if ($s->hours)<p class="mt-1 flex items-center gap-1.5 text-[15px] text-muted-foreground">{!! Icon::svg('clock', 'w-5 h-5 shrink-0') !!}{{ $s->hours }}</p>@endif
    </div>
    <div class="flex flex-wrap gap-2">
        @if ($s->map_url)<a href="{{ $s->map_url }}" rel="noopener" class="inline-flex items-center gap-1.5 rounded-xl border border-border px-4 h-11 font-medium">{!! Icon::svg('pin', 'w-5 h-5') !!}{{ Site::t('Маршрут в 2ГИС') }}</a>@endif
        @if ($s->whatsapp)<a href="{{ Site::route('wa') }}" rel="nofollow" class="inline-flex items-center gap-1.5 rounded-xl bg-wa text-white px-4 h-11 font-semibold">@include('storefront.partials.wa-icon', ['class' => 'w-5 h-5'])WhatsApp</a>@endif
        @if ($s->phone)<a href="tel:{{ preg_replace('/[^\d+]/', '', $s->phone) }}" class="inline-flex items-center gap-1.5 rounded-xl border border-border px-4 h-11 font-medium">{!! Icon::svg('call', 'w-5 h-5') !!}{{ Site::t('Позвонить') }}</a>@endif
    </div>
</section>
