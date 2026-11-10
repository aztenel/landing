{{--
    ERP frame of Prime Mobile OS (module «shell», TZ 04 §0.2–§0.3). Replaces Filament's layout:
    computer — menu on the left (236 px; icons only at 1024–1279 px) + topbar; iPhone (< 1024 px) — header,
    page and the bottom tab bar with the round «Скан». Mockups: erp-1-statistika.png, iphone-*.png.
--}}
@php
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;

    $livewire ??= null;
    $navigation = filament()->getNavigation();
    $scopes = $livewire?->getRenderHookScopes();
    $pmSupport = \App\Domain\Tenancy\Services\IntegratorAccess::active();
    $hotkeys = ($livewire && method_exists($livewire, 'hotkeys')) ? $livewire->hotkeys() : [];
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <div class="pm-shell flex min-h-screen w-full" x-data="{}">
        @include('shell.layout.sidebar', ['navigation' => $navigation])

        <div class="flex min-w-0 flex-1 flex-col">
            @if ($pmSupport)
                {{-- TZ 03 §13: everybody sees that the integrator is inside and until when --}}
                <div class="pm-safe-x flex min-h-10 items-center gap-2 bg-warn/15 py-2 text-[13px] font-medium text-foreground" role="status" data-pm-support>
                    <x-ui::icon name="shield" class="h-4 w-4 shrink-0" />
                    <span>{{ __('Идёт техподдержка до :time', ['time' => $pmSupport->support_until->timezone(config('app.timezone'))->format('H:i')]) }}</span>
                </div>
            @endif
            {{ FilamentView::renderHook(\App\Support\Ui\ShellHook::BANNER, scopes: $scopes) }}
            @include('shell.layout.topbar')
            @include('shell.layout.mobile-header')

            {{ FilamentView::renderHook(PanelsRenderHook::CONTENT_BEFORE, scopes: $scopes) }}

            <main class="fi-main pm-main pm-safe-x w-full flex-1 pb-[calc(96px+env(safe-area-inset-bottom))] pt-4 desk:pb-8 desk:pt-6">
                {{ FilamentView::renderHook(PanelsRenderHook::CONTENT_START, scopes: $scopes) }}

                {{ $slot }}

                {{ FilamentView::renderHook(PanelsRenderHook::CONTENT_END, scopes: $scopes) }}
            </main>

            {{ FilamentView::renderHook(PanelsRenderHook::CONTENT_AFTER, scopes: $scopes) }}
            {{ FilamentView::renderHook(PanelsRenderHook::FOOTER, scopes: $scopes) }}
        </div>

        @include('shell.layout.tabbar')
    </div>

    @include('shell.layout.overlays', ['hotkeys' => $hotkeys])
</x-filament-panels::layout.base>
