@php use App\Http\Storefront\Site; use App\Support\Ui\Icon; @endphp
<h1 class="text-[24px] sm:text-[28px] font-bold tracking-tight leading-tight">{{ $l->title(Site::$lang) }}</h1>
<p class="mt-1.5 flex flex-wrap items-center gap-2 text-[15px] text-muted-foreground">
    <button type="button" data-copy="{{ $l->public_code }}" class="inline-flex items-center gap-1 rounded-lg bg-white border border-border px-2 h-8 font-mono text-[13px] text-foreground" title="{{ Site::t('Копировать') }}">{{ $l->public_code }} {!! Icon::svg('copy', 'w-3.5 h-3.5') !!}</button>
    <span class="rounded-lg bg-muted px-2 h-8 leading-8 text-foreground text-[13px] font-medium">{{ $l->state === 'used' ? Site::t('Б/у') : Site::t('Новые') }}</span>
    @if ($l->state === 'used' && $l->battery_pct)<span class="inline-flex items-center gap-1 text-[14px]">{!! Icon::svg('battery', 'w-4 h-4') !!}{{ $l->battery_pct }}%</span>@endif
</p>
