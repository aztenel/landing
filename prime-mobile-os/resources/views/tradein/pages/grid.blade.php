<x-filament-panels::page>
<div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-[minmax(0,1fr)_360px]">
    <section class="rounded-xl border border-border bg-card">
        <h2 class="border-b border-border px-4 py-3 font-semibold">{{ __('Базовые цены (грейд A, батарея от 85 %)') }}</h2>
        <div class="flex flex-wrap gap-2 p-3">
            <select wire:model="row.model_id" class="rounded-lg border-input bg-background text-sm"><option value="">{{ __('Модель') }}</option>@foreach ($models as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            <select wire:model="row.memory_id" class="rounded-lg border-input bg-background text-sm"><option value="">{{ __('любая память') }}</option>@foreach ($memories as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            <input type="text" inputmode="numeric" wire:model="row.price" placeholder="{{ __('цена, ₸') }}" class="num w-32 rounded-lg border-input bg-background text-sm">
            <button type="button" wire:click="savePrice" class="h-10 rounded-lg bg-primary px-4 text-sm text-white">{{ __('Сохранить') }}</button>
        </div>
        @foreach ($prices as $p)
            <div class="flex items-center gap-3 border-t border-border px-4 py-2 text-sm" wire:key="p{{ $p->id }}">
                <span class="flex-1">{{ $models[$p->model_id] ?? '' }} {{ $memories[$p->memory_id] ?? '' }}</span>
                <span class="num font-semibold">{{ \App\Support\Money\Money::kztMinor((int) $p->base_price_kzt)->format() }}</span>
                <button type="button" wire:click="removePrice({{ $p->id }})" class="text-muted-foreground">×</button>
            </div>
        @endforeach
    </section>
    <section class="space-y-2 rounded-xl border border-border bg-card p-4">
        <h2 class="font-semibold">{{ __('Вычеты') }}</h2>
        @foreach (\App\Domain\TradeIn\Services\TradeInGrid::DEDUCTIONS as $code => $label)
            <div class="flex items-center gap-2 text-sm" wire:key="d{{ $code }}">
                <span class="flex-1">{{ __($label) }}</span>
                <input type="text" inputmode="decimal" wire:model="deductions.{{ $code }}.value" class="num w-24 rounded-lg border-input bg-background text-sm">
                <select wire:model="deductions.{{ $code }}.kind" class="rounded-lg border-input bg-background text-sm"><option value="amount">₸</option><option value="percent">%</option></select>
            </div>
        @endforeach
        <button type="button" wire:click="saveDeductions" class="mt-2 h-10 w-full rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Сохранить вычеты') }}</button>
    </section>
</div>
</x-filament-panels::page>
