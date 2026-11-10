<div class="relative">
    <button type="button" wire:click="openDialog" class="inline-flex h-10 items-center gap-1.5 rounded-lg px-3 text-[13px] hover:bg-muted"
            title="{{ __('Курс доллара') }}">
        <span class="text-muted-foreground">$1 =</span>
        <span class="num font-semibold">{{ $current ? $current.' ₸' : __('нет курса') }}</span>
    </button>

    @if ($open)
        <div class="fixed inset-0 z-50 flex items-start justify-center bg-black/30 p-4 pt-24" wire:keydown.escape="$set('open', false)">
            <div class="w-full max-w-sm rounded-xl border border-border bg-card p-5 shadow-xl" role="dialog" aria-labelledby="pm-rate-title">
                <h2 id="pm-rate-title" class="text-base font-semibold">{{ __('Курс доллара') }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $current ? __('Сейчас: $1 = :rate ₸ с :since', ['rate' => $current, 'since' => $since]) : __('Курс ещё не задан') }}
                </p>
                @if ($canSet)
                    <label class="mt-4 block text-sm font-medium" for="pm-rate">{{ __('Новый курс, ₸ за $1') }}</label>
                    <input id="pm-rate" type="text" inputmode="decimal" wire:model="rate" autofocus
                           class="num mt-1 w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary">
                    <label class="mt-3 block text-sm font-medium" for="pm-rate-comment">{{ __('Комментарий (необязательно)') }}</label>
                    <input id="pm-rate-comment" type="text" wire:model="comment" class="mt-1 w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary">
                    @if ($error)
                        <p class="mt-2 text-sm text-bad" role="alert">{{ $error }}</p>
                    @endif
                    @if ($confirm)
                        <div class="mt-3 rounded-lg bg-warn/10 p-3 text-sm text-warn" role="alert">{{ $confirm }}</div>
                        <div class="mt-4 flex gap-2">
                            <button type="button" wire:click="save(true)" class="h-11 flex-1 rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Да, поставить') }}</button>
                            <button type="button" wire:click="$set('confirm', null)" class="h-11 flex-1 rounded-lg border border-border text-sm">{{ __('Исправить') }}</button>
                        </div>
                    @else
                        <div class="mt-4 flex gap-2">
                            <button type="button" wire:click="save" class="h-11 flex-1 rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Сохранить') }}</button>
                            <button type="button" wire:click="$set('open', false)" class="h-11 flex-1 rounded-lg border border-border text-sm">{{ __('Отмена') }}</button>
                        </div>
                    @endif
                @else
                    <div class="mt-4 flex justify-end">
                        <button type="button" wire:click="$set('open', false)" class="h-11 rounded-lg border border-border px-4 text-sm">{{ __('Закрыть') }}</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
