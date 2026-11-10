<x-filament-panels::page>
@php
    $money = fn ($t, $c = 'KZT') => \App\Support\Money\Money::of(abs((int) $t), $c)->format();
    $color = fn ($due) => ! $due ? '' : ($due->lt(today()) ? 'text-bad font-semibold' : ($due->lte(today()->addDay()) ? 'text-orange-600' : ($due->lte(today()->addDays(5)) ? 'text-warn' : '')));
@endphp
<div class="space-y-4">
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-border bg-card p-4"><div class="text-xs text-muted-foreground">{{ __('Должны нам') }}</div><div class="num text-2xl font-semibold">{{ $money($totalOwed) }}</div>
            @if ($overdue->isNotEmpty())<div class="text-sm text-bad">{{ __('просрочено :s (:n)', ['s' => $money($overdue->sum('balance')), 'n' => $overdue->count()]) }}</div>@endif</div>
        @if ($canSupplier)<div class="rounded-xl border border-border bg-card p-4"><div class="text-xs text-muted-foreground">{{ __('Мы должны') }}</div>
            <div class="num text-2xl font-semibold">{{ $weOweUsd ? $money($weOweUsd, 'USD').' + ' : '' }}{{ $money($weOweKzt) }}</div>
            @if ($weOweUsd && $rate)<div class="num text-xs text-muted-foreground">≈ {{ $money(-$weOweKzt + (int) round(-$weOweUsd * $rate)) }} {{ __('по курсу') }} {{ $rate }}</div>@endif</div>@endif
    </div>
    <div class="-mx-1 flex gap-1 border-b border-border px-1 text-sm">
        <button type="button" wire:click="$set('tabName', 'customers')" class="h-10 px-3 {{ $tabName === 'customers' ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ __('Должны нам') }}</button>
        @if ($canSupplier)<button type="button" wire:click="$set('tabName', 'suppliers')" class="h-10 px-3 {{ $tabName === 'suppliers' ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ __('Мы должны') }}</button>@endif
        <button type="button" wire:click="$set('tabName', 'advances')" class="h-10 px-3 {{ $tabName === 'advances' ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ __('Авансы клиентов') }}</button>
    </div>
    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>@elseif ($flash)<div class="text-sm text-ok" role="status">{{ $flash }}</div>@endif

    <div class="space-y-2">
        @if ($tabName === 'customers')
            @forelse ($owed as $b)
                @php($c = $names[$b->counterparty_id] ?? null)
                <div class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm" wire:key="c{{ $b->id }}">
                    <a href="{{ \App\Filament\Money\Pages\Customers::getUrl(['id' => $b->counterparty_id]) }}" class="min-w-0 flex-1"><span class="block font-medium">{{ $c?->name }}</span><span class="num text-xs text-muted-foreground">{{ $c?->phone }}</span></a>
                    <span class="num text-xs {{ $color($b->nearest_due_date) }}">{{ $b->nearest_due_date ? __('до :d', ['d' => $b->nearest_due_date->format('d.m.Y')]) : '' }}{{ $b->nearest_due_date && $b->nearest_due_date->lt(today()) ? ' · '.__('просрочено :n дн.', ['n' => (int) $b->nearest_due_date->diffInDays(today())]) : '' }}</span>
                    <span class="num font-semibold">{{ $money($b->balance) }}</span>
                    @if ($c?->phone)<a href="https://wa.me/{{ preg_replace('/\D/', '', $c->phone) }}?text={{ rawurlencode(__('Здравствуйте, :n! Напоминаем о платеже :s в Prime Mobile.', ['n' => $c->name, 's' => $money($b->balance)]).($b->nearest_due_date ? ' '.__('Срок — :d', ['d' => $b->nearest_due_date->format('d.m.Y')]) : '')) }}" target="_blank" class="h-9 rounded-lg border border-border px-3 text-xs leading-9">{{ __('Напомнить') }}</a>@endif
                    <a href="{{ route('erp.print.reconciliation', ['type' => 'customer', 'id' => $b->counterparty_id]) }}" target="_blank" class="inline-flex h-9 items-center rounded-lg border border-border px-3 text-xs">{{ __('Акт сверки') }}</a>
                    <button type="button" wire:click="payCustomer({{ $b->counterparty_id }})" class="h-9 rounded-lg bg-primary px-3 text-xs font-semibold text-white">{{ __('Принять оплату') }}</button>
                </div>
            @empty
                <p class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">{{ __('Долгов клиентов нет') }}</p>
            @endforelse
        @elseif ($tabName === 'suppliers')
            @forelse ($weOwe as $b)
                <div class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm" wire:key="s{{ $b->id }}">
                    <span class="min-w-0 flex-1 font-medium">{{ $supplierNames[$b->counterparty_id] ?? '' }}</span>
                    <span class="num text-xs {{ $color($b->nearest_due_date) }}">{{ $b->nearest_due_date ? __('до :d', ['d' => $b->nearest_due_date->format('d.m.Y')]) : '' }}</span>
                    <span class="num font-semibold">{{ $money($b->balance, $b->currency) }}</span>
                    <a href="{{ route('erp.print.reconciliation', ['type' => 'supplier', 'id' => $b->counterparty_id]) }}" target="_blank" class="inline-flex h-9 items-center rounded-lg border border-border px-3 text-xs">{{ __('Акт сверки') }}</a>
                    @if ($canPaySupplier)<button type="button" wire:click="paySupplier({{ $b->counterparty_id }}, '{{ $b->currency }}')" class="h-9 rounded-lg bg-primary px-3 text-xs font-semibold text-white">{{ __('Оплатить') }}</button>@endif
                </div>
            @empty
                <p class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">{{ __('Долгов поставщикам нет') }}</p>
            @endforelse
        @else
            @forelse ($advances as $b)
                <div class="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm"><span class="flex-1">{{ $names[$b->counterparty_id]?->name }}</span><span class="num font-semibold">{{ $money($b->balance) }}</span></div>
            @empty
                <p class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">{{ __('Авансов нет') }}</p>
            @endforelse
        @endif
    </div>

    @if ($pay)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
            <div class="w-full space-y-3 rounded-t-2xl bg-card p-5 desk:w-[440px] desk:rounded-2xl" role="dialog">
                <h3 class="text-lg font-semibold">{{ $pay['kind'] === 'customer' ? __('Принять оплату долга') : __('Оплатить поставщику') }}</h3>
                <label class="block text-sm">{{ __('Сумма') }}<input type="text" inputmode="decimal" wire:model="pay.amount" class="num mt-1 w-full rounded-lg border-input bg-background text-base" autofocus></label>
                @if ($pay['kind'] === 'customer')
                    <label class="block text-sm">{{ __('Способ') }}<select wire:model="pay.method" class="mt-1 w-full rounded-lg border-input bg-background text-base">@foreach ($methods as $m)<option value="{{ $m->code }}">{{ $m->name_ru }}</option>@endforeach</select></label>
                @else
                    <label class="block text-sm">{{ __('Со счёта') }}<select wire:model="pay.account_id" class="mt-1 w-full rounded-lg border-input bg-background text-base"><option value="">{{ __('Выберите') }}</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
                    <p class="text-xs text-muted-foreground">{{ __('Долг в :c. Если счёт в другой валюте — долг уменьшится по курсу дня.', ['c' => $pay['currency'] === 'USD' ? '$' : '₸']) }}</p>
                @endif
                @if ($error)<p class="text-sm text-bad">{{ $error }}</p>@endif
                <div class="flex justify-end gap-2"><button type="button" wire:click="$set('pay', null)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Отмена') }}</button><button type="button" wire:click="submit" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Провести') }}</button></div>
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
