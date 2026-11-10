<x-filament-panels::page>
@php($money = fn ($t) => $t === null ? '—' : \App\Support\Money\Money::kztMinor((int) $t)->format())
<div class="space-y-4">
    <div class="-mx-1 flex gap-1 border-b border-border px-1 text-sm">
        @foreach (['prices' => __('Цены'), 'history' => __('История цен')] as $k => $label)
            <button type="button" wire:click="$set('tabName', '{{ $k }}')" class="h-10 px-3 {{ $tabName === $k ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ $label }}</button>
        @endforeach
    </div>
    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>
    @elseif ($flash)<div class="text-sm text-ok" role="status">{{ $flash }}</div>@endif

    @if ($tabName === 'prices')
        <div class="flex flex-wrap items-center gap-3">
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="{{ __('Модель') }}" class="h-10 min-w-[220px] flex-1 rounded-lg border-border bg-card text-base md:max-w-sm md:text-sm">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="inStockOnly" class="h-4 w-4 rounded text-primary"> {{ __('только в наличии или с ценой') }}</label>
        </div>
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border text-left text-xs text-muted-foreground"><tr>
                    <th class="px-4 py-3">{{ __('Вариант') }}</th><th class="px-2 py-3 text-right">{{ __('На складе') }}</th>
                    <th class="px-2 py-3 text-right">{{ __('Цена, ₸') }}</th><th class="hidden px-4 py-3 md:table-cell">{{ __('Последняя смена') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($variants as $v)
                        @php($p = $prices[$v->id] ?? null)
                        <tr class="border-b border-border last:border-0" wire:key="v{{ $v->id }}">
                            <td class="px-4 py-2.5">{{ $v->title() }}</td>
                            <td class="num px-2 py-2.5 text-right text-muted-foreground">{{ (int) $v->in_stock }}</td>
                            <td class="px-2 py-2.5 text-right">
                                @if ($canEdit)
                                    <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="changes.{{ $v->id }}" placeholder="{{ $p ? number_format($p->price_kzt / 100, 0, '', ' ') : '—' }}"
                                           class="num h-9 w-32 rounded-lg border-input text-right text-sm {{ isset($pending[$v->id]) ? 'bg-warn/15' : 'bg-background' }}">
                                @else
                                    <span class="num font-semibold">{{ $money($p?->price_kzt) }}</span>
                                @endif
                            </td>
                            <td class="hidden px-4 py-2.5 text-xs text-muted-foreground md:table-cell">{{ $p?->updated_at?->setTimezone(\App\Support\Tenancy\CurrentOrganization::instance()->require()->timezone)->format('d.m.Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div>{{ $variants->links() }}</div>

        @if ($pending)
            <div class="sticky bottom-20 z-10 flex flex-wrap items-center gap-3 rounded-xl bg-primary px-4 py-3 text-sm text-white shadow-lg desk:bottom-4">
                <span class="font-medium">{{ __('Изменено :n цен', ['n' => count($pending)]) }}</span>
                <button type="button" wire:click="discard" class="ml-auto h-9 rounded-lg px-3 opacity-90">{{ __('Отменить') }}</button>
                <button type="button" wire:click="$set('applyOpen', true)" class="h-9 rounded-lg bg-white px-4 font-semibold text-primary">{{ __('Применить…') }}</button>
            </div>
        @endif
    @else
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border text-left text-xs text-muted-foreground"><tr><th class="px-4 py-3">{{ __('Когда') }}</th><th class="px-2 py-3">{{ __('Что') }}</th><th class="px-2 py-3 text-right">{{ __('Было → стало') }}</th><th class="px-4 py-3">{{ __('Кто') }}</th></tr></thead>
                <tbody>
                    @forelse ($history as $h)
                        <tr class="border-b border-border last:border-0">
                            <td class="num px-4 py-2.5 text-xs text-muted-foreground">{{ \Carbon\CarbonImmutable::parse($h->created_at)->setTimezone(\App\Support\Tenancy\CurrentOrganization::instance()->require()->timezone)->format('d.m.Y H:i') }}</td>
                            <td class="px-2 py-2.5">{{ ['variant' => __('Вариант'), 'stock_unit' => __('Телефон'), 'accessory' => __('Аксессуар')][$h->subject_type] ?? $h->subject_type }} #{{ $h->subject_id }}</td>
                            <td class="num px-2 py-2.5 text-right">{{ $money($h->old_price) }} → {{ $money($h->new_price) }}</td>
                            <td class="px-4 py-2.5 text-xs text-muted-foreground">{{ $users[$h->user_id] ?? '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-8 text-center text-muted-foreground">{{ __('Цены ещё не менялись') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($applyOpen)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
            <div class="w-full rounded-t-2xl bg-card p-5 desk:w-[480px] desk:rounded-2xl" role="dialog">
                <h3 class="text-lg font-semibold">{{ __('Применить :n цен', ['n' => count($pending)]) }}</h3>
                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    @foreach (['переоценка', 'акция', 'курс', 'конкурент'] as $r)
                        <button type="button" wire:click="$set('reason', '{{ $r }}')" class="h-8 rounded-full border px-3 {{ $reason === $r ? 'border-primary text-primary' : 'border-border' }}">{{ __($r) }}</button>
                    @endforeach
                </div>
                <input type="text" wire:model="reason" class="mt-2 w-full rounded-lg border-input bg-background text-base" placeholder="{{ __('Причина') }}">
                <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="updateUnits" class="h-4 w-4 rounded text-primary"> {{ __('Обновить цену у оприходованных новых телефонов') }}</label>
                @if ($error)<p class="mt-2 text-sm text-bad">{{ $error }}</p>@endif
                <div class="mt-4 flex justify-end gap-2"><button type="button" wire:click="$set('applyOpen', false)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Назад') }}</button><button type="button" wire:click="apply" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Применить') }}</button></div>
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
