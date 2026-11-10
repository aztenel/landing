<x-filament-panels::page>
@php($input = 'mt-1 w-full rounded-lg border-input bg-background text-base')
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex gap-1 rounded-lg bg-muted p-1 text-sm font-medium">
        <button type="button" wire:click="$set('mode', 'write_off')" class="h-10 flex-1 rounded-md {{ $mode === 'write_off' ? 'bg-card shadow-sm' : 'text-muted-foreground' }}">{{ __('Списание') }}</button>
        <button type="button" wire:click="$set('mode', 'supplier')" class="h-10 flex-1 rounded-md {{ $mode === 'supplier' ? 'bg-card shadow-sm' : 'text-muted-foreground' }}">{{ __('Возврат поставщику') }}</button>
    </div>
    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>@elseif ($flash)<div class="text-sm text-ok" role="status">{{ $flash }}</div>@endif
    <section class="space-y-3 rounded-xl border border-border bg-card p-4">
        @include('warehouse.partials.goods')
        @if ($mode === 'write_off')
            <label class="block text-sm font-medium">{{ __('Причина') }}<input type="text" wire:model="reason" placeholder="{{ __('брак, потеря, на запчасти…') }}" class="{{ $input }}"></label>
            <p class="text-xs text-muted-foreground">{{ __('Списание сверх лимита роли — заявкой владельцу.') }}</p>
        @else
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm font-medium">{{ __('Поставщик') }}<select wire:model="supplierId" class="{{ $input }}"><option value="">{{ __('Выберите') }}</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
                <label class="block text-sm font-medium">{{ __('Сумма от поставщика, ₸') }}<input type="text" inputmode="numeric" wire:model="refund" class="{{ $input }} num"></label>
                <label class="block text-sm font-medium">{{ __('Как') }}<select wire:model.live="refundMode" class="{{ $input }}"><option value="debt">{{ __('в счёт нашего долга') }}</option><option value="money">{{ __('деньгами') }}</option></select></label>
                @if ($refundMode === 'money')<label class="block text-sm font-medium">{{ __('На счёт') }}<select wire:model="accountId" class="{{ $input }}"><option value="">{{ __('Выберите') }}</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>@endif
            </div>
            <label class="block text-sm font-medium">{{ __('Комментарий') }}<input type="text" wire:model="reason" class="{{ $input }}"></label>
        @endif
        <div class="flex justify-end"><button type="button" wire:click="post" wire:loading.attr="disabled" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ __('Провести') }}</button></div>
    </section>
    @if ($recent->isNotEmpty())
        <section class="rounded-xl border border-border bg-card p-4 text-sm"><h3 class="font-semibold">{{ __('Последние') }}</h3>
            @foreach ($recent as $d)<div class="num flex justify-between border-b border-border py-1.5 text-xs last:border-0"><span>{{ $d->number }} · {{ $d->type->label() }}</span><span>{{ $d->posted_at?->format('d.m.Y') }}</span></div>@endforeach
        </section>
    @endif
</div>
</x-filament-panels::page>
