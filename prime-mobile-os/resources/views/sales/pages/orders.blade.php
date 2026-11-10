<x-filament-panels::page>
@php($money = fn ($t) => \App\Support\Money\Money::kztMinor((int) $t)->format())
@php($input = 'mt-1 w-full rounded-lg border-input bg-background text-base')
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <div class="-mx-1 flex flex-1 gap-1 border-b border-border px-1 text-sm">
            @foreach (['open' => __('Активные'), 'closed' => __('Закрытые')] as $k => $label)
                <button type="button" wire:click="$set('tabName', '{{ $k }}')" class="h-10 px-3 {{ $tabName === $k ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ $label }}</button>
            @endforeach
        </div>
        <button type="button" wire:click="newOrder" class="h-10 rounded-lg bg-primary px-4 text-sm font-semibold text-white">+ {{ __('Новый заказ') }}</button>
    </div>
    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>
    @elseif ($flash)<div class="text-sm text-ok" role="status">{{ $flash }}</div>@endif

    @if ($form !== null)
        <section class="max-w-2xl space-y-3 rounded-xl border border-border bg-card p-4">
            <h2 class="font-semibold">{{ __('Новый заказ (бронь)') }}</h2>
            @if ($pickedCustomer)
                <div class="flex items-center justify-between rounded-lg bg-muted px-3 py-2 text-sm"><span>{{ $pickedCustomer->name }} · <span class="num">{{ $pickedCustomer->phone }}</span></span><button type="button" wire:click="$set('form.customer_id', null)" class="text-xs">{{ __('Сменить') }}</button></div>
            @else
                <label class="block text-sm font-medium">{{ __('Клиент') }}<input type="text" wire:model.live.debounce.300ms="customerQuery" placeholder="{{ __('Имя или телефон') }}" class="{{ $input }}"></label>
                @foreach ($customerResults as $c)<button type="button" wire:click="$set('form.customer_id', {{ $c->id }})" class="block w-full px-3 py-1.5 text-left text-sm hover:bg-muted">{{ $c->name }} · <span class="num">{{ $c->phone }}</span></button>@endforeach
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="block text-sm font-medium">{{ __('Телефон в наличии (скан)') }}<span class="flex gap-2"><input type="text" wire:model="form.code" wire:keydown.enter="findUnit" class="{{ $input }} num"><button type="button" wire:click="findUnit" class="mt-1 h-10 rounded-lg border border-border px-3 text-sm">{{ __('Найти') }}</button></span></label>
                    @if ($pickedUnit)<p class="mt-1 text-xs text-ok">{{ $pickedUnit->variant?->title() }} · {{ $pickedUnit->public_code }}</p>@endif</div>
                <div><label class="block text-sm font-medium">{{ __('или «под заказ»: модель') }}<input type="text" wire:model.live.debounce.300ms="variantQuery" class="{{ $input }}"></label>
                    @foreach ($variantResults as $v)<button type="button" wire:click="pickVariant({{ $v->id }})" class="block w-full px-3 py-1.5 text-left text-sm hover:bg-muted">{{ $v->title() }}</button>@endforeach</div>
                <label class="block text-sm font-medium">{{ __('Цена, ₸') }}<input type="text" inputmode="numeric" wire:model="form.price" class="{{ $input }} num"></label>
                <label class="block text-sm font-medium">{{ __('Предоплата, ₸') }}<input type="text" inputmode="numeric" wire:model="form.prepay" class="{{ $input }} num"></label>
                <label class="block text-sm font-medium">{{ __('Способ предоплаты') }}<select wire:model="form.method" class="{{ $input }}"><option value="cash">{{ __('Наличные') }}</option><option value="kaspi_qr">Kaspi QR</option><option value="kaspi_transfer">{{ __('Kaspi перевод') }}</option><option value="card">{{ __('Карта') }}</option></select></label>
                <label class="block text-sm font-medium">{{ __('Комментарий') }}<input type="text" wire:model="form.note" class="{{ $input }}"></label>
            </div>
            <div class="flex justify-end gap-2"><button type="button" wire:click="$set('form', null)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Отмена') }}</button><button type="button" wire:click="save" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Оформить') }}</button></div>
        </section>
    @endif

    <div class="space-y-2">
        @forelse ($orders as $o)
            @php($c = $customers[$o->customer_id] ?? null)
            <div class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm" wire:key="o{{ $o->id }}">
                <div class="min-w-0 flex-1">
                    <div class="font-medium"><span class="num">{{ $numbers[$o->document_id] ?? '' }}</span> · {{ $c?->name }}</div>
                    <div class="text-xs text-muted-foreground">{{ $o->stock_unit_id ? ($units[$o->stock_unit_id]?->variant?->title().' · '.$units[$o->stock_unit_id]?->public_code) : (($variants[$o->variant_id] ?? null)?->title().' · '.__('под заказ')) }}</div>
                    <div class="text-xs text-muted-foreground">{{ __('бронь до') }} {{ $o->hold_until->setTimezone($tz)->format('d.m H:i') }}@if ($o->prepayment) · {{ __('предоплата') }} {{ $money($o->prepayment) }}@endif</div>
                </div>
                <span class="num font-semibold">{{ $money($o->price) }}</span>
                <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-ok/10 text-ok' => $o->status === 'active', 'bg-bad/10 text-bad' => $o->status === 'overdue', 'bg-muted text-muted-foreground' => ! in_array($o->status, ['active', 'overdue'])])>{{ ['active' => __('активен'), 'overdue' => __('просрочен'), 'sold' => __('продан'), 'cancelled' => __('отменён'), 'expired' => __('истёк')][$o->status] ?? $o->status }}</span>
                @if ($o->isOpen())
                    <button type="button" wire:click="sell({{ $o->id }})" class="h-9 rounded-lg bg-primary px-3 text-xs font-semibold text-white">{{ __('Продать') }}</button>
                    <button type="button" wire:click="extend({{ $o->id }})" class="h-9 rounded-lg border border-border px-3 text-xs">{{ __('+3 дня') }}</button>
                    <button type="button" wire:click="cancel({{ $o->id }})" wire:confirm="{{ __('Отменить заказ? Предоплата вернётся тем же способом') }}" class="h-9 rounded-lg px-3 text-xs text-bad hover:bg-bad/10">{{ __('Отменить') }}</button>
                @endif
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">{{ __('Заказов нет') }}</p>
        @endforelse
    </div>
</div>
</x-filament-panels::page>
