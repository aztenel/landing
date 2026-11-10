@props(['wordmark' => true, 'size' => 'md'])
@php($uid = 'lg'.substr(md5(uniqid('', true)), 0, 6))
@php($mark = $size === 'lg' ? 'h-[44px] w-[30px]' : 'h-[32px] w-[22px]')
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    {{-- logo mark of docs/design/src/erp.py: phone body, speaker, glass glare --}}
    <svg viewBox="0 0 44 64" class="{{ $mark }}" aria-hidden="true">
        <defs><linearGradient id="{{ $uid }}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" style="stop-color:rgb(var(--logo-1, 42 75 215))"/><stop offset=".55" style="stop-color:rgb(var(--logo-2, 42 75 215))"/><stop offset="1" style="stop-color:rgb(var(--logo-3, 17 17 19))"/></linearGradient></defs>
        <rect x="3" y="2.5" width="38" height="59" rx="9" fill="none" stroke="url(#{{ $uid }})" stroke-width="3.2"/>
        <rect x="17" y="8" width="10" height="2.6" rx="1.3" style="fill:rgb(var(--logo-2, 42 75 215))"/>
        <path d="M12 46 32 18" stroke="url(#{{ $uid }})" stroke-width="3.6" stroke-linecap="round"/>
        <path d="M21 49 32 33.5" stroke="url(#{{ $uid }})" stroke-width="2.2" stroke-linecap="round" opacity=".55"/>
    </svg>
    @if ($wordmark)
        <span class="whitespace-nowrap leading-none {{ $size === 'lg' ? 'text-xl' : 'text-[15px]' }} tracking-[-.01em]"><span class="font-semibold">Prime</span> <span class="font-normal text-muted-foreground">Mobile</span></span>
    @endif
</span>
