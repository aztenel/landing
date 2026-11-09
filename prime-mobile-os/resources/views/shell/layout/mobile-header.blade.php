@php
    use App\Support\Ui\Shell;
    use App\Support\Ui\ShellHook;
    use Filament\Support\Facades\FilamentView;

    $user = Shell::user();
@endphp
{{-- iPhone header (TZ 04 §0.3, iphone-vladelec-1-statistika.png): logo left, avatar right, safe area on top. --}}
<header class="pm-mobile-header sticky top-0 z-20 bg-background/95 desk:hidden" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    <div class="pm-safe-x flex h-14 items-center gap-3">
        <a href="{{ filament()->getHomeUrl() }}" aria-label="Prime Mobile"><x-ui::logo size="lg" /></a>
        <div class="ml-auto flex items-center gap-2">
            {{ FilamentView::renderHook(ShellHook::MOBILE_HEADER_END) }}
            @if ($user)
                <button type="button" class="grid h-11 w-11 place-items-center rounded-full bg-muted text-[14px] font-semibold"
                        x-on:click="open = true" aria-label="{{ __('Меню пользователя') }}">{{ Shell::initials($user->name) }}</button>
            @endif
        </div>
    </div>
    @if ($user)
        <template x-teleport="body">
            <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-end desk:hidden" role="dialog" aria-modal="true">
                <div class="absolute inset-0 bg-black/40" x-on:click="open = false" x-show="open" x-transition.opacity></div>
                <div class="relative w-full rounded-t-[20px] bg-card p-2 pb-[calc(12px+env(safe-area-inset-bottom))]" x-show="open"
                     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0">
                    <div class="mx-auto mb-2 mt-1 h-1.5 w-10 rounded-full bg-muted"></div>
                    @include('shell.layout.user-menu')
                    <button type="button" class="mt-1 flex h-12 w-full items-center justify-center rounded-xl border border-border text-[15px] font-medium" x-on:click="open = false">{{ __('Отмена') }}</button>
                </div>
            </div>
        </template>
    @endif
</header>
