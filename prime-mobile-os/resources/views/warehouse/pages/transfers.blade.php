<x-filament-panels::page>
<div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-2">
    <section class="space-y-3 rounded-xl border border-border bg-card p-4">
        <h2 class="font-semibold">{{ __('Отправить') }}</h2>
        @if ($error)<div class="rounded-lg bg-bad/5 px-3 py-2 text-sm text-bad" role="alert">{{ $error }}</div>@elseif ($flash)<div class="text-sm text-ok">{{ $flash }}</div>@endif
        <label class="block text-sm font-medium">{{ __('Куда') }}<select wire:model="toStore" class="mt-1 w-full rounded-lg border-input bg-background text-base"><option value="">{{ __('Магазин') }}</option>@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
        @include('warehouse.partials.goods')
        <div class="flex justify-end"><button type="button" wire:click="send" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ __('Отправить') }}</button></div>
        <h3 class="pt-2 text-sm font-semibold">{{ __('Исходящие') }}</h3>
        @foreach ($outgoing as $d)<div class="num flex justify-between border-b border-border py-1.5 text-xs"><span>{{ $d->number }} → {{ $storeNames[$ops[$d->id]?->to_store_id] ?? '' }} <a href="{{ route('erp.print.invoice', $d) }}" target="_blank" class="text-primary">{{ __('накладная') }}</a></span><span>{{ ['in_transit' => __('в пути'), 'received' => __('принято')][$ops[$d->id]?->status] ?? '' }}{{ $d->status->value === 'reversed' ? ' · '.__('сторно') : '' }}</span></div>@endforeach
    </section>
    <section class="space-y-3 rounded-xl border border-border bg-card p-4">
        <h2 class="font-semibold">{{ __('Входящие') }} · {{ $incoming->count() }}</h2>
        @forelse ($incoming as $d)
            <button type="button" wire:click="open({{ $d->id }})" class="flex w-full justify-between rounded-lg border px-3 py-2 text-sm {{ $openId === $d->id ? 'border-primary' : 'border-border' }}"><span class="num">{{ $d->number }}</span><span>{{ __('из') }} {{ $storeNames[$d->store_id] ?? '' }} · {{ __('в пути :h ч', ['h' => (int) $d->posted_at?->diffInHours(now())]) }}</span></button>
        @empty
            <p class="text-sm text-muted-foreground">{{ __('Входящих перемещений нет') }}</p>
        @endforelse
        @if ($openId)
            <div class="divide-y divide-border">
                @foreach ($openLines as $l)
                    <div class="flex items-center gap-3 py-2 text-sm" wire:key="ol{{ $l->id }}">
                        <span class="flex-1">{{ $l->stock_unit_id ? \App\Domain\Inventory\Models\StockUnit::query()->find($l->stock_unit_id)?->variant?->title().' · '.\App\Domain\Inventory\Models\StockUnit::query()->find($l->stock_unit_id)?->public_code : \App\Domain\Catalog\Models\Accessory::query()->find($l->accessory_id)?->name_ru.' · '.$l->qty.' '.__('шт.') }}</span>
                        @if ($l->stock_unit_id)
                            <label class="flex items-center gap-1 text-xs"><input type="checkbox" wire:model="decide.{{ $l->id }}.refuse" class="h-4 w-4 rounded"> {{ __('отказ') }}</label>
                        @else
                            <label class="flex items-center gap-1 text-xs">{{ __('отказ, шт.') }} <input type="number" min="0" max="{{ $l->qty }}" wire:model="decide.{{ $l->id }}.refused" class="num h-8 w-16 rounded-lg border-input text-sm"></label>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="flex justify-end"><button type="button" wire:click="accept" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ __('Принять') }}</button></div>
        @endif
    </section>
</div>
</x-filament-panels::page>
