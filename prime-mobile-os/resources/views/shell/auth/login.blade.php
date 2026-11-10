<x-filament-panels::page.simple>
    {{-- Э1.1: вход по телефону и паролю --}}
    <x-filament-panels::form id="form" wire:submit="authenticate">
        {{ $this->form }}
        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
    </x-filament-panels::form>
    <div class="mt-4 text-center text-sm">
        <button type="button" wire:click="$toggle('showForgot')" class="font-medium text-primary hover:underline">{{ __('Забыли пароль?') }}</button>
        @if ($showForgot)
            <p class="mt-2 text-muted-foreground">{{ __('Пароль сбрасывает владелец фирмы: он пришлёт вам ссылку в WhatsApp. Свой пароль владелец восстанавливает через интегратора.') }}</p>
        @endif
    </div>
</x-filament-panels::page.simple>
