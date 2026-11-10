<x-filament-panels::page>
@php
    $input = 'mt-1 w-full rounded-lg border-input bg-background text-base';
    $fmt = fn ($b) => \App\Support\Money\Money::of(abs((int) $b->balance), $b->currency)->format();
@endphp
<div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="{{ __('Название') }}" class="h-10 min-w-[200px] flex-1 rounded-lg border-border bg-card text-base md:max-w-sm md:text-sm">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="showHidden" class="h-4 w-4 rounded text-primary"> {{ __('показать скрытых') }}</label>
            @if ($canEdit)<button type="button" wire:click="edit" class="ml-auto h-10 rounded-lg bg-primary px-4 text-sm font-semibold text-white">+ {{ __('Поставщик') }}</button>@endif
        </div>
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border text-left text-xs text-muted-foreground"><tr><th class="px-4 py-3">{{ __('Название') }}</th><th class="hidden px-2 py-3 md:table-cell">{{ __('Телефон') }}</th><th class="px-2 py-3">{{ __('Валюта') }}</th><th class="px-4 py-3 text-right">{{ __('Мы должны') }}</th></tr></thead>
                <tbody>
                    @forelse ($suppliers as $s)
                        <tr class="cursor-pointer border-b border-border last:border-0 hover:bg-muted/40 {{ $open?->id === $s->id ? 'bg-primary/5' : '' }}" wire:click="$set('openId', {{ $s->id }})" wire:key="s{{ $s->id }}">
                            <td class="px-4 py-3"><span class="font-medium">{{ $s->name }}</span>@if ($s->is_repair_master)<span class="ml-2 rounded-full bg-muted px-2 text-xs">{{ __('мастер') }}</span>@endif @unless ($s->is_active)<span class="ml-2 text-xs text-muted-foreground">{{ __('скрыт') }}</span>@endunless</td>
                            <td class="num hidden px-2 py-3 text-muted-foreground md:table-cell">{{ $s->phone }}</td>
                            <td class="px-2 py-3">{{ $s->currency === 'USD' ? '$' : '₸' }}</td>
                            <td class="num px-4 py-3 text-right">
                                @foreach ($debts[$s->id] ?? [] as $b)
                                    @if ((int) $b->balance < 0)<span class="block font-semibold text-bad">{{ $fmt($b) }}</span>@elseif ((int) $b->balance > 0)<span class="block text-ok">{{ __('аванс') }} {{ $fmt($b) }}</span>@endif
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-8 text-center text-muted-foreground">{{ __('Поставщиков пока нет. Добавьте здесь или прямо в приходе') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        @if ($form !== null)
            <form wire:submit="save" class="space-y-3 rounded-xl border border-border bg-card p-4">
                <h2 class="font-semibold">{{ ($form['id'] ?? null) ? __('Поставщик') : __('Новый поставщик') }}</h2>
                <label class="block text-sm font-medium">{{ __('Название') }}<input type="text" wire:model="form.name" class="{{ $input }}"></label>
                @error('form.name')<p class="text-sm text-bad">{{ $message }}</p>@enderror
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm font-medium">{{ __('Контактное лицо') }}<input type="text" wire:model="form.contact_person" class="{{ $input }}"></label>
                    <label class="block text-sm font-medium">{{ __('Город') }}<input type="text" wire:model="form.city" class="{{ $input }}"></label>
                    <label class="block text-sm font-medium">{{ __('Телефон') }}<input type="tel" wire:model="form.phone" class="{{ $input }} num"></label>
                    <label class="block text-sm font-medium">WhatsApp<input type="tel" wire:model="form.whatsapp" class="{{ $input }} num"></label>
                    <label class="block text-sm font-medium">{{ __('Валюта расчётов') }}<select wire:model="form.currency" class="{{ $input }}"><option value="KZT">₸</option><option value="USD">$</option></select></label>
                    <label class="block text-sm font-medium">{{ __('Срок оплаты, дней') }}<input type="number" wire:model="form.payment_days" class="{{ $input }} num"></label>
                    <label class="block text-sm font-medium">{{ __('БИН / ИИН') }}<input type="text" inputmode="numeric" wire:model="form.bin_iin" class="{{ $input }} num"></label>
                </div>
                @error('form.bin_iin')<p class="text-sm text-bad">{{ $message }}</p>@enderror
                <label class="block text-sm font-medium">{{ __('Реквизиты') }}<textarea wire:model="form.requisites" rows="2" class="{{ $input }}"></textarea></label>
                <label class="block text-sm font-medium">{{ __('Комментарий') }}<textarea wire:model="form.comment" rows="2" class="{{ $input }}"></textarea></label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.is_repair_master" class="h-4 w-4 rounded text-primary"> {{ __('Мастер ремонта') }}</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.is_active" class="h-4 w-4 rounded text-primary"> {{ __('Показывать в списках') }}</label>
                <div class="flex justify-end gap-2"><button type="button" wire:click="$set('form', null)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Отмена') }}</button><button type="submit" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Сохранить') }}</button></div>
            </form>
        @elseif ($open)
            <div class="space-y-3 rounded-xl border border-border bg-card p-4 text-sm">
                <div class="flex items-start justify-between"><h2 class="text-lg font-semibold">{{ $open->name }}</h2>@if ($canEdit)<button type="button" wire:click="edit({{ $open->id }})" class="h-9 rounded-lg border border-border px-3 text-xs">{{ __('Изменить') }}</button>@endif</div>
                <dl class="grid grid-cols-2 gap-2">
                    <div><dt class="text-xs text-muted-foreground">{{ __('Контакт') }}</dt><dd>{{ $open->contact_person ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">{{ __('Телефон') }}</dt><dd class="num">{{ $open->phone ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">{{ __('Валюта') }}</dt><dd>{{ $open->currency === 'USD' ? '$' : '₸' }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">{{ __('Срок оплаты') }}</dt><dd>{{ $open->payment_days ? $open->payment_days.' '.__('дн.') : '—' }}</dd></div>
                </dl>
                @if ($open->whatsapp)<a href="https://wa.me/{{ preg_replace('/\D/', '', $open->whatsapp) }}" target="_blank" class="inline-flex h-9 items-center rounded-lg border border-border px-3 text-xs">WhatsApp</a>@endif
                <div><h3 class="mt-2 font-medium">{{ __('Приходы') }}</h3>
                    @forelse ($purchases as $d)<div class="flex justify-between border-b border-border py-1.5 text-xs"><span class="num">{{ $d->number }}</span><span class="text-muted-foreground">{{ $d->posted_at?->format('d.m.Y') }}</span></div>
                    @empty<p class="text-xs text-muted-foreground">{{ __('Приходов ещё нет') }}</p>@endforelse
                </div>
                @if ($phones->isNotEmpty())
                    <div><h3 class="mt-2 font-medium">{{ __('Телефоны от него') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ $phones->map(fn ($n, $s) => \App\Domain\Inventory\Enums\UnitStatus::labelOf(\App\Domain\Inventory\Enums\UnitStatus::tryFrom($s)).': '.$n)->implode(' · ') }}</p></div>
                @endif
            </div>
        @else
            <p class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">{{ __('Выберите поставщика') }}</p>
        @endif
    </div>
</div>
</x-filament-panels::page>
