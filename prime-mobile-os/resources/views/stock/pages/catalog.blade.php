<x-filament-panels::page>
@php($input = 'mt-1 w-full rounded-lg border-input bg-background text-base')
<div class="space-y-4">
    <div class="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1 text-sm">
        @foreach (['models' => __('Модели'), 'accessories' => __('Аксессуары'), 'kk' => __('Нет перевода')] as $k => $label)
            <button type="button" wire:click="$set('tabName', '{{ $k }}')" class="h-10 shrink-0 px-3 {{ $tabName === $k ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ $label }}</button>
        @endforeach
    </div>
    @if ($flash)<p class="text-sm text-ok" role="status">{{ $flash }}</p>@endif
    <input type="search" wire:model.live.debounce.300ms="q" placeholder="{{ __('Поиск') }}" class="h-10 w-full max-w-sm rounded-lg border-border bg-card text-base md:text-sm">

    @if ($tabName === 'models')
        <div class="grid gap-4 desk:grid-cols-[320px_minmax(0,1fr)]">
            <div class="max-h-[70vh] overflow-y-auto rounded-xl border border-border bg-card p-2 text-sm">
                @foreach ($types as $type)
                    <div class="px-2 pt-2 text-xs font-medium uppercase text-muted-foreground">{{ $type->name_ru }}</div>
                    @foreach ($families->where('product_type_id', $type->id) as $f)
                        @if (isset($models[$f->id]))
                            <div class="px-2 pt-2 font-medium">{{ $f->name }}</div>
                            @foreach ($models[$f->id] as $m)
                                <button type="button" wire:click="openModel({{ $m->id }})" class="block w-full rounded-md px-4 py-1.5 text-left {{ $modelId === $m->id ? 'bg-primary/10 text-primary' : 'hover:bg-muted' }}">{{ $m->name_ru }}@unless ($m->name_kk)<span class="ml-1 text-xs text-warn">kk</span>@endunless</button>
                            @endforeach
                        @endif
                    @endforeach
                @endforeach
                @if ($canEdit)
                    <div class="mt-3 border-t border-border p-2">
                        <select wire:model="newModel.family_id" class="w-full rounded-lg border-input bg-background text-sm"><option value="">{{ __('Семейство') }}</option>@foreach ($families as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select>
                        <div class="mt-2 flex gap-2"><input type="text" wire:model="newModel.name_ru" placeholder="{{ __('Новая модель') }}" class="min-w-0 flex-1 rounded-lg border-input bg-background text-sm"><button type="button" wire:click="addModel" class="h-9 rounded-lg bg-primary px-3 text-sm text-white">+</button></div>
                        @error('newModel.family_id')<p class="text-xs text-bad">{{ $message }}</p>@enderror
                    </div>
                @endif
            </div>
            <div>
                @if ($modelId)
                    <form wire:submit="saveModel" class="space-y-3 rounded-xl border border-border bg-card p-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block text-sm font-medium">{{ __('Название (ru)') }}<input type="text" wire:model="model.name_ru" @disabled(! $canEdit) class="{{ $input }}"></label>
                            <label class="block text-sm font-medium">{{ __('Название (kk)') }}<input type="text" wire:model="model.name_kk" @disabled(! $canEdit) class="{{ $input }}"></label>
                            <label class="block text-sm font-medium">{{ __('Название (en)') }}<input type="text" wire:model="model.name_en" @disabled(! $canEdit) class="{{ $input }}"></label>
                            <label class="block text-sm font-medium">{{ __('Год выхода') }}<input type="number" wire:model="model.release_year" @disabled(! $canEdit) class="{{ $input }} num"></label>
                        </div>
                        @error('model.name_ru')<p class="text-sm text-bad">{{ $message }}</p>@enderror
                        @if ($canEdit)<div class="flex justify-end"><button type="submit" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Сохранить') }}</button></div>@endif
                    </form>
                    <div class="mt-4 rounded-xl border border-border bg-card">
                        <h3 class="border-b border-border px-4 py-3 font-semibold">{{ __('Варианты') }}</h3>
                        @foreach ($variants as $v)<div class="border-b border-border px-4 py-2 text-sm last:border-0">{{ $v->title() }}</div>@endforeach
                        @if ($canEdit)
                            <div class="flex flex-wrap gap-2 p-3">
                                <select wire:model="newVariant.memory_id" class="rounded-lg border-input bg-background text-sm"><option value="">{{ __('Память') }}</option>@foreach ($memories as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
                                <select wire:model="newVariant.color_id" class="rounded-lg border-input bg-background text-sm"><option value="">{{ __('Цвет') }}</option>@foreach ($colors as $c)<option value="{{ $c->id }}">{{ $c->name_ru }}</option>@endforeach</select>
                                <button type="button" wire:click="addVariant" class="h-9 rounded-lg border border-border px-3 text-sm">+ {{ __('Вариант') }}</button>
                            </div>
                        @endif
                    </div>
                @else
                    <p class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">{{ __('Выберите модель слева') }}</p>
                @endif
            </div>
        </div>

    @elseif ($tabName === 'accessories')
        @if ($canEdit && $accessory === null)<button type="button" wire:click="editAccessory" class="h-10 rounded-lg bg-primary px-4 text-sm font-semibold text-white">+ {{ __('Аксессуар') }}</button>@endif
        @if ($accessory !== null)
            <form wire:submit="saveAccessory" class="max-w-2xl space-y-3 rounded-xl border border-border bg-card p-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm font-medium">{{ __('Название (ru)') }}<input type="text" wire:model="accessory.name_ru" class="{{ $input }}"></label>
                    <label class="block text-sm font-medium">{{ __('Название (kk)') }}<input type="text" wire:model="accessory.name_kk" class="{{ $input }}"></label>
                    <label class="block text-sm font-medium">{{ __('Артикул') }}<input type="text" wire:model="accessory.sku" class="{{ $input }} num"></label>
                    <label class="block text-sm font-medium">{{ __('Штрихкод') }}<span class="flex gap-2"><input type="text" wire:model="accessory.barcode" class="{{ $input }} num"><button type="button" wire:click="generateBarcode" class="mt-1 h-10 shrink-0 rounded-lg border border-border px-2 text-xs">{{ __('Создать') }}</button></span></label>
                    <label class="block text-sm font-medium">{{ __('Категория') }}<select wire:model="accessory.category_id" class="{{ $input }}"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name_ru }}</option>@endforeach</select></label>
                    <label class="block text-sm font-medium">{{ __('Гарантия, мес.') }}<input type="number" wire:model="accessory.warranty_months" class="{{ $input }} num"></label>
                </div>
                @foreach (['accessory.name_ru', 'accessory.sku', 'accessory.barcode'] as $f)@error($f)<p class="text-sm text-bad">{{ $message }}</p>@enderror @endforeach
                <div class="flex justify-end gap-2"><button type="button" wire:click="$set('accessory', null)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Отмена') }}</button><button type="submit" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Сохранить') }}</button></div>
            </form>
        @endif
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border text-left text-xs text-muted-foreground"><tr><th class="px-4 py-3">{{ __('Название') }}</th><th class="px-2 py-3">{{ __('Категория') }}</th><th class="num hidden px-2 py-3 md:table-cell">{{ __('Артикул / штрихкод') }}</th><th class="px-4 py-3"></th></tr></thead>
                <tbody>
                    @foreach ($accessories as $a)
                        <tr class="border-b border-border last:border-0" wire:key="a{{ $a->id }}">
                            <td class="px-4 py-2.5">{{ $a->name_ru }}@if ($a->name_kk)<span class="block text-xs text-muted-foreground">{{ $a->name_kk }}</span>@endif</td>
                            <td class="px-2 py-2.5 text-muted-foreground">{{ $a->category?->name_ru }}</td>
                            <td class="num hidden px-2 py-2.5 text-xs text-muted-foreground md:table-cell">{{ $a->sku }} · {{ $a->barcode }}</td>
                            <td class="px-4 py-2.5 text-right">@if ($canEdit)<button type="button" wire:click="editAccessory({{ $a->id }})" class="text-xs text-primary">{{ __('Изменить') }}</button>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    @else
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            @forelse ($untranslated as $row)
                <div class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-2.5 text-sm last:border-0" wire:key="k{{ $row['key'] }}">
                    <span class="w-24 text-xs text-muted-foreground">{{ $row['kind'] }}</span>
                    <span class="min-w-[160px] flex-1">{{ $row['name'] }}</span>
                    @if ($canTranslate)
                        <input type="text" wire:model="kk.{{ $row['key'] }}" placeholder="{{ __('қазақша') }}" class="h-9 w-56 rounded-lg border-input bg-background text-sm">
                        <button type="button" wire:click="saveKk('{{ $row['key'] }}')" class="h-9 rounded-lg border border-border px-3 text-xs">{{ __('Сохранить') }}</button>
                    @endif
                </div>
            @empty
                <p class="p-8 text-center text-sm text-muted-foreground">{{ __('Все названия переведены') }}</p>
            @endforelse
        </div>
    @endif
</div>
</x-filament-panels::page>
