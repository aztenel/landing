@php
    use App\Http\Storefront\Site;
    use App\Support\Ui\Icon;
    /** @var \App\Domain\Storefront\Models\Listing $l */
    $img = $l->photo('md');
    $sale = $l->discountPercent();
@endphp
<a href="{{ Site::route('phone', ['code' => $l->public_code]) }}" class="group bg-white rounded-2xl border border-border p-3 flex flex-col min-w-0 hover:border-primary/40 {{ $class ?? '' }}">
    <div class="relative aspect-square rounded-xl bg-[#F3F4F6] overflow-hidden">
        @if ($img)
            <img src="{{ $img }}" srcset="{{ $l->photo('sm') }} 300w, {{ $img }} 600w" sizes="(min-width: 1024px) 240px, 45vw" width="600" height="600" loading="lazy" alt="{{ $l->title(Site::$lang) }}" class="w-full h-full object-cover">
        @endif
        <div class="absolute left-2 top-2 flex flex-wrap gap-1">
            @if ($sale)<span class="rounded-md bg-bad text-white text-[11px] font-semibold px-1.5 py-0.5">−{{ $sale }}%</span>@endif
            @if (in_array('hit', (array) $l->badges, true))<span class="rounded-md bg-foreground text-white text-[11px] font-semibold px-1.5 py-0.5">{{ Site::t('Хит') }}</span>@endif
            @if (in_array('new', (array) $l->badges, true))<span class="rounded-md bg-primary text-white text-[11px] font-semibold px-1.5 py-0.5">{{ Site::t('Новинка') }}</span>@endif
        </div>
        @if ($l->public_status === 'reserved')
            <span class="absolute inset-x-2 bottom-2 rounded-md bg-white/90 text-muted-foreground text-[12px] font-medium text-center py-1">{{ Site::t('Забронирован') }}</span>
        @endif
    </div>
    <p class="mt-2.5 text-[14px] sm:text-[15px] font-medium leading-snug line-clamp-2">{{ $l->title(Site::$lang) }}</p>
    @if ($l->state === 'used')
        <p class="mt-1 flex flex-wrap items-center gap-1.5 text-[12px] text-muted-foreground">
            <span class="rounded-md bg-muted px-1.5 py-0.5 text-foreground">{{ Site::grade($l->grade) ?? Site::t('Б/у') }}</span>
            @if ($l->battery_pct)<span class="inline-flex items-center gap-0.5">{!! Icon::svg('battery', 'w-3.5 h-3.5') !!}{{ $l->battery_pct }}%</span>@endif
        </p>
    @endif
    <div class="mt-auto pt-2">
        <p class="text-[17px] font-bold tracking-tight">{{ Site::money($l->price) }}
            @if ($l->compare_at_price > $l->price)<s class="ml-1 text-[13px] font-normal text-muted-foreground">{{ Site::money($l->compare_at_price) }}</s>@endif
        </p>
        @if ($l->installment_from)<p class="text-[12px] text-muted-foreground">{{ Site::t('от :sum × :m', ['sum' => Site::money($l->installment_from), 'm' => Site::months($l->installment_months)]) }}</p>@endif
        @if (($l->stock_count ?? 0) > 1)<p class="text-[12px] text-ok font-medium">{{ Site::t('в наличии :n', ['n' => $l->stock_count]) }}</p>@endif
        @if (Site::stores()->count() > 1 && ($s = Site::store($l->store_ref)))<p class="text-[12px] text-muted-foreground truncate">{{ $s->name }}</p>@endif
    </div>
</a>
