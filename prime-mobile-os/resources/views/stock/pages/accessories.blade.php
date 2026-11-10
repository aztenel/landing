<x-filament-panels::page>
@php($money = fn ($t) => $t === null ? '—' : \App\Support\Money\Money::kztMinor((int) $t)->format())
<div class="space-y-4" x-data x-on:pm-scan.window="$wire.scanned($event.detail.code)">
    <div class="flex flex-wrap items-center gap-2">
        <h1 class="mr-auto text-2xl font-semibold tracking-tight">{{ __('Склад') }}</h1>
    </div>
    <div class="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1 text-sm">
        <a href="{{ \App\Filament\Stock\Pages\Phones::getUrl() }}" class="flex h-10 items-center px-3 text-muted-foreground">{{ __('Телефоны') }}</a>
        <span class="flex h-10 items-center border-b-2 border-primary px-3 font-semibold">{{ __('Аксессуары') }}</span>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="q" data-pm-scan-target placeholder="{{ __('Название, артикул или штрихкод') }}" class="h-10 min-w-[220px] flex-1 rounded-lg border-border bg-card text-base md:max-w-sm md:text-sm">
        <select wire:model.live="category_id" class="h-10 rounded-lg border-border bg-card py-0 text-sm"><option value="">{{ __('Категория') }}</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name_ru }}</option>@endforeach</select>
        <select wire:model.live="stock" class="h-10 rounded-lg border-border bg-card py-0 text-sm"><option value="">{{ __('Остаток') }}</option><option value="low">{{ __('заканчивается') }}</option><option value="none">{{ __('нет на остатке') }}</option></select>
    </div>
    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <table class="w-full text-sm">
            <thead class="border-b border-border text-left text-xs text-muted-foreground"><tr>
                <th class="px-4 py-3">{{ __('Название') }}</th><th class="hidden px-2 py-3 md:table-cell">{{ __('Артикул / штрихкод') }}</th>
                <th class="px-2 py-3 text-right">{{ __('Остаток') }}</th><th class="px-2 py-3 text-right">{{ __('Цена') }}</th>
                @if ($canCost)<th class="hidden px-4 py-3 text-right md:table-cell">{{ __('Средняя себестоимость') }}</th>@endif
            </tr></thead>
            <tbody>
                @forelse ($items as $a)
                    <tr class="border-b border-border last:border-0" wire:key="a{{ $a->id }}">
                        <td class="px-4 py-3"><span class="block font-medium">{{ $a->name_ru }}</span>@if ($a->name_kk)<span class="block text-xs text-muted-foreground">{{ $a->name_kk }}</span>@endif</td>
                        <td class="num hidden px-2 py-3 text-xs text-muted-foreground md:table-cell">{{ $a->sku }}<br>{{ $a->barcode }}</td>
                        <td class="num px-2 py-3 text-right {{ (int) $a->qty === 0 ? 'text-bad' : ((int) $a->qty <= 3 ? 'text-warn' : '') }}">{{ (int) $a->qty }} {{ __('шт.') }}
                            @if ($multiStore && isset($perStore[$a->id]))<span class="block text-xs text-muted-foreground">{{ $perStore[$a->id]->map(fn ($b) => ($storeNames[$b->location_id] ?? '').' '.$b->qty)->implode(' · ') }}</span>@endif</td>
                        <td class="num px-2 py-3 text-right font-semibold">
                            @if ($canPrice)<button type="button" wire:click="$set('editId', {{ $a->id }})" class="hover:text-primary">{{ $money($a->price) }}</button>@else{{ $money($a->price) }}@endif
                        </td>
                        @if ($canCost)<td class="num hidden px-4 py-3 text-right text-muted-foreground md:table-cell">{{ $money($a->avg_cost) }}</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-10 text-center text-muted-foreground">{{ __('Аксессуаров не нашлось') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $items->links() }}</div>

    @if ($editId)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
            <div class="w-full rounded-t-2xl bg-card p-5 desk:w-[400px] desk:rounded-2xl" role="dialog">
                <h3 class="text-lg font-semibold">{{ __('Новая цена') }}</h3>
                <input type="text" inputmode="numeric" wire:model="newPrice" autofocus class="num mt-3 w-full rounded-lg border-input bg-background text-base">
                @if ($error)<p class="mt-2 text-sm text-bad">{{ $error }}</p>@endif
                <div class="mt-4 flex justify-end gap-2"><button type="button" wire:click="$set('editId', null)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Отмена') }}</button><button type="button" wire:click="savePrice" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Сохранить') }}</button></div>
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
