@php
    use App\Support\Ui\ShellHook;
    use Filament\Support\Facades\FilamentView;

    $status = FilamentView::renderHook(ShellHook::TOPBAR_STATUS);
    $rate = FilamentView::renderHook(ShellHook::TOPBAR_RATE);
    $bell = FilamentView::renderHook(ShellHook::TOPBAR_BELL);
@endphp
{{-- Topbar (TZ 04 §0.2 п. 2): global search «/» or Ctrl+K, shift status, rate $ (Э22), bell (Э28). --}}
<header class="pm-topbar sticky top-0 z-20 hidden h-16 shrink-0 items-center gap-3 border-b border-border bg-background/95 pm-safe-x desk:flex">
    <div class="min-w-0" data-pm-search>
        @livewire(\Filament\Livewire\GlobalSearch::class)
    </div>
    <div class="ml-auto flex items-center gap-2">
        {{ $status }}
        <span x-data x-cloak x-show="$store.pm && $store.pm.legacy"
              class="hidden h-10 place-items-center rounded-lg bg-muted px-3 text-[12px] font-medium hd:grid">{{ __('Режим: старый ноутбук') }}</span>
        @if ($rate->isNotEmpty())
            {{ $rate }}
        @else
            @livewire(\App\Livewire\Shell\RateDialog::class)
        @endif
        @if ($bell->isNotEmpty())
            {{ $bell }}
        @else
            <x-ui::bell />
        @endif
    </div>
</header>
