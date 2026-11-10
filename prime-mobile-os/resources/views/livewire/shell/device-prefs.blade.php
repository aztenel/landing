<div x-data x-on:pm-legacy-changed.window="
        document.documentElement.classList.toggle('legacy', $event.detail.legacy);
        try { localStorage.setItem('pm.legacy', $event.detail.legacy ? '1' : '0'); } catch (e) {}">
    @if ($open)
        <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/30 p-4 pt-24" wire:keydown.escape="$set('open', false)">
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-5 shadow-xl" role="dialog" aria-labelledby="pm-device-title">
                <h2 id="pm-device-title" class="text-base font-semibold">{{ __('Это устройство') }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">{{ __('Настройка действует на этом компьютере для всех, кто на нём работает.') }}</p>
                <label class="mt-4 flex min-h-[44px] cursor-pointer items-start gap-3 rounded-lg border border-border p-3">
                    <input type="checkbox" wire:model="legacy" class="mt-1 h-5 w-5 rounded border-input text-primary focus:ring-primary">
                    <span>
                        <span class="block text-sm font-medium">{{ __('Старый ноутбук') }}</span>
                        <span class="block text-sm text-muted-foreground">{{ __('Крупнее текст, чётче рамки, без анимаций. Для ноутбука на Windows 7.') }}</span>
                    </span>
                </label>
                <div class="mt-4 flex gap-2">
                    <button type="button" wire:click="save" class="h-11 flex-1 rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Сохранить') }}</button>
                    <button type="button" wire:click="$set('open', false)" class="h-11 flex-1 rounded-lg border border-border text-sm">{{ __('Отмена') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
