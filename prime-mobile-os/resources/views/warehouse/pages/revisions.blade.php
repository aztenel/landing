<x-filament-panels::page>
<div class="mx-auto max-w-4xl space-y-4" x-data x-on:pm-scan.window="$wire.scan($event.detail.code)">
    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>@elseif ($flash)<div class="text-sm text-ok" role="status">{{ $flash }}</div>@endif
    @if (! $rev)
        <section class="space-y-3 rounded-xl border border-border bg-card p-4">
            <h2 class="font-semibold">{{ __('Начать ревизию магазина') }}</h2>
            <select wire:model="scope" class="w-full rounded-lg border-input bg-background text-base"><option value="all">{{ __('Всё') }}</option><option value="phones">{{ __('Только телефоны') }}</option><option value="accessories">{{ __('Только аксессуары') }}</option></select>
            <button type="button" wire:click="start" class="h-11 w-full rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Начать') }}</button>
        </section>
    @else
        <section class="rounded-xl border border-border bg-card p-4">
            <div class="flex gap-2">
                <input type="text" data-pm-scan-target autofocus x-on:keydown.enter.prevent="$el.value.trim() && ($wire.scan($el.value.trim()), $el.value = '')" placeholder="{{ __('Сканируйте всё, что лежит в магазине') }}" class="h-12 min-w-0 flex-1 rounded-lg border-2 border-primary bg-background text-base">
                <button type="button" class="flex h-12 w-12 items-center justify-center rounded-lg bg-primary text-white desk:hidden" x-on:click="PM.scanner.open()" aria-label="{{ __('Камера') }}"><x-ui::icon name="camera" class="h-5 w-5" /></button>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
                <div class="rounded-lg bg-ok/10 p-3"><div class="num text-2xl font-bold text-ok">{{ $r['found']->count() }}</div>{{ __('совпало') }}</div>
                <div class="rounded-lg bg-bad/10 p-3"><div class="num text-2xl font-bold text-bad">{{ $r['missing']->count() }}</div>{{ __('не найдено') }}</div>
                <div class="rounded-lg bg-warn/10 p-3"><div class="num text-2xl font-bold text-warn">{{ $r['extra']->count() + $r['back']->count() }}</div>{{ __('лишнее') }}</div>
            </div>
        </section>
        @foreach (['missing' => __('Не найдено'), 'back' => __('Найдено после списания (вернётся на склад)'), 'extra' => __('Лишнее (другой магазин или неизвестный код — оформите перемещением или приходом)')] as $k => $title)
            @if ($r[$k]->isNotEmpty())
                <section class="rounded-xl border border-border bg-card p-4 text-sm"><h3 class="font-semibold">{{ $title }}</h3>
                    @foreach ($r[$k] as $u)<div class="flex justify-between border-b border-border py-1.5 last:border-0"><span>{{ $u->variant?->title() }}</span><span class="num text-xs text-muted-foreground">{{ $u->public_code }}{{ isset($u->status) && $u->status?->value === 'reserved' ? ' · '.__('в корзине') : '' }}</span></div>@endforeach
                </section>
            @endif
        @endforeach
        @if ($r['acc'])
            <section class="rounded-xl border border-border bg-card p-4 text-sm"><h3 class="font-semibold">{{ __('Аксессуары с расхождением') }}</h3>
                @foreach ($r['acc'] as $a)<div class="flex justify-between border-b border-border py-1.5 last:border-0"><span>{{ $a['name'] }}</span><span class="num">{{ __('учёт :h · насчитали :c', ['h' => $a['have'], 'c' => $a['counted']]) }} <b class="{{ $a['diff'] < 0 ? 'text-bad' : 'text-warn' }}">{{ $a['diff'] > 0 ? '+' : '' }}{{ $a['diff'] }}</b></span></div>@endforeach
            </section>
        @endif
        <div class="flex justify-end gap-2">
            <button type="button" wire:click="cancel" wire:confirm="{{ __('Отменить ревизию? Сканы удалятся') }}" class="h-11 rounded-lg border border-border px-4 text-sm">{{ __('Отменить') }}</button>
            @if ($canPost)<button type="button" wire:click="post" wire:confirm="{{ __('Провести итог? Недостачи будут списаны') }}" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ __('Провести итог') }}</button>
            @else<span class="text-sm text-muted-foreground">{{ __('Итог проводит администратор') }}</span>@endif
        </div>
    @endif
</div>
</x-filament-panels::page>
