@php
    use App\Support\Ui\Shell;
    $user = Shell::user();
@endphp
{{-- Page hotkeys for the global dispatcher (TZ 04 §0.6) --}}
<script>window.PM_PAGE_HOTKEYS = @json(array_values($hotkeys));</script>

@if ($user)
    {{-- Э29: «Это устройство» — old laptop mode and other device settings --}}
    @livewire(\App\Livewire\Shell\DevicePrefs::class)

    {{-- Idle warning (TZ 03 §9 п. 2) --}}
    <div x-data x-cloak x-show="$store.pmLock && $store.pmLock.warning" x-transition.opacity
         class="fixed inset-x-0 top-0 z-[60] flex justify-center p-3" role="status" aria-live="polite">
        <div class="flex items-center gap-2 rounded-xl bg-foreground px-4 py-2.5 text-[13.5px] font-medium text-background shadow-lg">
            <x-ui::icon name="clock" class="h-4 w-4" />
            <span>{{ __('Экран закроется через') }} <span x-text="$store.pmLock.secondsLeft"></span> {{ __('с. Нажмите любую клавишу') }}</span>
        </div>
    </div>

    {{-- Э1.3 lock screen over the current page --}}
    <div x-data="pmLockScreen" x-cloak x-show="$store.pmLock && $store.pmLock.locked"
         class="pm-lock-backdrop fixed inset-0 z-[70] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="pm-lock-title">
        @include('shell.auth.partials.lock-form', ['user' => $user, 'ajax' => true])
    </div>

    {{-- First visit from Chrome ≤ 109: offer «старый ноутбук» (TZ 04 Э29, Допущение) --}}
    <div x-data="pmLegacyOffer" x-cloak x-show="show" class="fixed bottom-4 right-4 z-40 hidden w-[380px] rounded-xl border border-border bg-card p-4 shadow-lg desk:block" role="dialog">
        <div class="flex gap-3">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted"><x-ui::icon name="laptop" class="h-4 w-4" /></span>
            <div class="min-w-0">
                <div class="text-[14px] font-semibold">{{ __('Похоже, это старый компьютер') }}</div>
                <p class="mt-0.5 text-[13px] text-muted-foreground">{{ __('Включить режим «старый ноутбук»? Текст станет крупнее, работа — быстрее') }}</p>
                <div class="mt-3 flex gap-2">
                    <button type="button" class="h-9 rounded-lg bg-primary px-3.5 text-[13.5px] font-medium text-primary-foreground" x-on:click="enable()">{{ __('Включить') }}</button>
                    <button type="button" class="h-9 rounded-lg border border-border bg-card px-3.5 text-[13.5px] font-medium hover:bg-muted" x-on:click="later()">{{ __('Не сейчас') }}</button>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- Hotkeys cheat sheet «?» --}}
<div x-data x-cloak x-show="$store.pmHotkeys && $store.pmHotkeys.help" x-on:keydown.escape.window="$store.pmHotkeys.help = false"
     class="fixed inset-0 z-[65] hidden items-center justify-center bg-black/40 p-4 desk:flex" x-bind:class="$store.pmHotkeys && $store.pmHotkeys.help ? '!flex' : ''" role="dialog" aria-modal="true">
    <div class="w-full max-w-[520px] rounded-xl border border-border bg-card p-5 shadow-lg" x-on:click.outside="$store.pmHotkeys.help = false">
        <div class="flex items-center justify-between">
            <h2 class="text-[17px] font-semibold">{{ __('Горячие клавиши') }}</h2>
            <button type="button" class="grid h-9 w-9 place-items-center rounded-lg hover:bg-muted" x-on:click="$store.pmHotkeys.help = false" aria-label="{{ __('Закрыть') }}"><x-ui::icon name="x" class="h-4 w-4" /></button>
        </div>
        <div class="mt-3 divide-y divide-border text-[13.5px]">
            <template x-for="row in $store.pmHotkeys.list()" x-bind:key="row.key">
                <div class="flex items-center justify-between py-2">
                    <span x-text="row.label"></span>
                    <kbd class="rounded border border-border px-1.5 font-mono text-[11.5px] text-muted-foreground" x-text="row.key"></kbd>
                </div>
            </template>
        </div>
        <p class="mt-3 text-[12px] text-muted-foreground">{{ __('F1, F5, F11 и F12 работают как обычно в браузере.') }}</p>
    </div>
</div>

{{-- Camera scanner (iPhone): Code-128, EAN-13, QR; flashlight; manual input when there is no camera --}}
@include('shell.layout.scanner-sheet')
