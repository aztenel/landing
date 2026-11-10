<x-filament-panels::page>
@php($input = 'mt-1 w-full rounded-lg border-input bg-background text-base')
<div class="space-y-4" x-data x-on:pm-scan.window="$wire.set('code', $event.detail.code)">
    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>@elseif ($flash)<div class="text-sm text-ok" role="status">{{ $flash }}</div>@endif
    <section class="max-w-2xl space-y-3 rounded-xl border border-border bg-card p-4">
        <h2 class="font-semibold">{{ __('Принять обращение') }}</h2>
        <label class="block text-sm font-medium">{{ __('IMEI или PM-код проданного телефона') }}<input type="text" wire:model="code" data-pm-scan-target class="{{ $input }} num"></label>
        <label class="block text-sm font-medium">{{ __('Неисправность со слов клиента') }}<textarea wire:model="complaint" rows="2" class="{{ $input }}"></textarea></label>
        <label class="block text-sm font-medium">{{ __('Комплект') }}<input type="text" wire:model="kit" placeholder="{{ __('телефон, кабель…') }}" class="{{ $input }}"></label>
        <div class="flex justify-end"><button type="button" wire:click="accept" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ __('Принять') }}</button></div>
    </section>
    <div class="space-y-2">
        @forelse ($cases as $c)
            @php($u = $units[$c->stock_unit_id] ?? null)
            <div class="rounded-xl border border-border bg-card px-4 py-3 text-sm" wire:key="c{{ $c->id }}">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="num font-semibold">{{ $c->number }}</span><span>{{ $u?->variant?->title() }}</span><span class="num text-xs text-muted-foreground">{{ $u?->public_code }}</span>
                    <a href="{{ route('erp.print.warranty_case', $c->id) }}" target="_blank" class="text-xs text-primary">{{ __('квитанция') }}</a>
                    <span class="ml-auto rounded-full bg-muted px-2 py-0.5 text-xs">{{ __(\App\Domain\Warehouse\Models\WarrantyCase::STATUSES[$c->status] ?? $c->status) }}</span>
                </div>
                <div class="mt-1 text-xs text-muted-foreground">{{ $c->complaint }}{{ $c->result ? ' → '.$c->result : '' }}</div>
                @if (\App\Domain\Warehouse\Services\Warranty::NEXT[$c->status] ?? [])
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @if (in_array('at_service', \App\Domain\Warehouse\Services\Warranty::NEXT[$c->status]))<select wire:model="serviceId" class="h-9 rounded-lg border-input bg-background text-xs"><option value="">{{ __('сервис') }}</option>@foreach ($services as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>@endif
                        @if (in_array('ready', \App\Domain\Warehouse\Services\Warranty::NEXT[$c->status]))<input type="text" wire:model="result" placeholder="{{ __('что сделано') }}" class="h-9 rounded-lg border-input bg-background text-xs">@endif
                        @foreach (\App\Domain\Warehouse\Services\Warranty::NEXT[$c->status] as $to)
                            <button type="button" wire:click="move({{ $c->id }}, '{{ $to }}')" class="h-9 rounded-lg border border-border px-3 text-xs">{{ __(\App\Domain\Warehouse\Models\WarrantyCase::STATUSES[$to]) }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">{{ __('Гарантийных обращений нет') }}</p>
        @endforelse
    </div>
</div>
</x-filament-panels::page>
