<x-filament-panels::page>
@php($money = fn ($t) => \App\Support\Money\Money::kztMinor((int) $t)->format())
<div class="space-y-3">
    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>
    @elseif ($flash)<div class="text-sm text-ok" role="status">{{ $flash }}</div>@endif
    @forelse ($units as $u)
        @php($days = $u->received_at ? (int) $u->received_at->diffInDays(now()) : 0)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm" wire:key="u{{ $u->id }}">
            <a href="{{ route('erp.stock.units.show', $u->public_code ?? $u->id) }}" class="min-w-0 flex-1">
                <span class="block font-medium">{{ $u->variant?->title() }}</span>
                <span class="block text-xs text-muted-foreground">{{ $u->public_code }} · {{ $u->grade }}{{ $u->battery_percent ? ' · '.$u->battery_percent.'%' : '' }} · {{ \App\Domain\Inventory\Enums\UnitStatus::labelOf($u->status) }}</span>
            </a>
            <span class="num text-xs {{ $days > 7 ? 'text-bad' : ($days > 3 ? 'text-warn' : 'text-muted-foreground') }}">{{ $days }} {{ __('дн.') }}</span>
            @if ($canCost)<span class="num text-xs text-muted-foreground">{{ __('себест.') }} {{ $money($u->cost_kzt) }}</span>@endif
            @if ($canAddCost)<button type="button" wire:click="$set('costFor', {{ $u->id }})" class="h-9 rounded-lg border border-border px-3 text-xs">+ {{ __('Затрата') }}</button>@endif
            @if ($canReady && $u->status->value === 'preparing')
                <input type="text" inputmode="numeric" wire:model="prices.{{ $u->id }}" placeholder="{{ $u->sale_price ? number_format($u->sale_price / 100, 0, '', ' ') : __('цена ₸') }}" class="num h-9 w-28 rounded-lg border-input bg-background text-sm">
                <button type="button" wire:click="ready({{ $u->id }})" class="h-9 rounded-lg bg-primary px-3 text-xs font-semibold text-white">{{ __('Готов к продаже') }}</button>
            @endif
        </div>
    @empty
        <p class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">{{ __('На подготовке ничего нет. Б/у телефоны попадают сюда после Trade-in и прихода б/у') }}</p>
    @endforelse

    @if ($costFor)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
            <div class="w-full space-y-3 rounded-t-2xl bg-card p-5 desk:w-[460px] desk:rounded-2xl" role="dialog">
                <h3 class="text-lg font-semibold">{{ __('Затрата на подготовку') }}</h3>
                <select wire:model="cost.kind" class="w-full rounded-lg border-input bg-background text-base">@foreach (['battery' => __('Батарея'), 'screen' => __('Экран'), 'body' => __('Корпус'), 'repair' => __('Ремонт у партнёра'), 'other' => __('Другое')] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                <input type="text" inputmode="numeric" wire:model="cost.amount" placeholder="{{ __('Сумма, ₸') }}" class="num w-full rounded-lg border-input bg-background text-base">
                <select wire:model.live="cost.pay" class="w-full rounded-lg border-input bg-background text-base"><option value="account">{{ __('Оплачено') }}</option><option value="master">{{ __('В долг мастеру') }}</option></select>
                @if ($cost['pay'] === 'master')
                    <select wire:model="cost.supplier_id" class="w-full rounded-lg border-input bg-background text-base"><option value="">{{ __('Мастер') }}</option>@foreach ($masters as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
                    <input type="date" wire:model="cost.due" class="w-full rounded-lg border-input bg-background text-base">
                @else
                    <select wire:model="cost.account_id" class="w-full rounded-lg border-input bg-background text-base"><option value="">{{ __('Откуда') }}</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                @endif
                <input type="text" wire:model="cost.note" placeholder="{{ __('Комментарий') }}" class="w-full rounded-lg border-input bg-background text-base">
                @if ($error)<p class="text-sm text-bad">{{ $error }}</p>@endif
                <div class="flex justify-end gap-2"><button type="button" wire:click="$set('costFor', null)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Отмена') }}</button><button type="button" wire:click="saveCost" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Добавить') }}</button></div>
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
