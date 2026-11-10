<x-filament-panels::page>
@php($money = fn ($t) => \App\Support\Money\Money::kztMinor((int) $t)->format())
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <label class="mr-auto flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="overGrid" class="h-4 w-4 rounded text-primary"> {{ __('выше сетки') }}</label>
        @if (\App\Support\Ui\Access::allows('tradein.grid'))<a href="{{ \App\Filament\TradeIn\Pages\Grid::getUrl() }}" class="inline-flex h-10 items-center rounded-lg border border-border px-4 text-sm">{{ __('Сетка выкупа') }}</a>@endif
        <a href="{{ \App\Filament\TradeIn\Pages\NewAct::getUrl() }}" class="inline-flex h-10 items-center rounded-lg bg-primary px-4 text-sm font-semibold text-white">+ {{ __('Новый выкуп') }}</a>
    </div>
    <div class="overflow-x-auto rounded-xl border border-border bg-card">
        <table class="w-full text-sm">
            <thead class="border-b border-border text-left text-xs text-muted-foreground"><tr><th class="px-4 py-3">№</th><th class="px-2 py-3">{{ __('Модель') }}</th><th class="hidden px-2 py-3 md:table-cell">{{ __('Сдал') }}</th><th class="px-2 py-3 text-right">{{ __('Сумма') }}</th><th class="hidden px-2 py-3 md:table-cell">{{ __('Принял') }}</th><th class="px-4 py-3"></th></tr></thead>
            <tbody>
                @forelse ($docs as $d)
                    @php($a = $acts[$d->id] ?? null)
                    <tr class="border-b border-border last:border-0" wire:key="d{{ $d->id }}">
                        <td class="num px-4 py-3">{{ $d->number }}<span class="block text-xs text-muted-foreground">{{ $d->posted_at?->format('d.m.Y') }}</span>@if ($d->status->value === 'reversed')<span class="text-xs">{{ __('сторно') }}</span>@endif</td>
                        <td class="px-2 py-3">{{ $variants[$a?->variant_id]?->title() }}<span class="block text-xs text-muted-foreground">{{ $a?->grade }}{{ $a?->battery_percent ? ' · '.$a->battery_percent.'%' : '' }}{{ $a?->offset_amount ? ' · '.__('в зачёт') : '' }}</span></td>
                        <td class="hidden px-2 py-3 md:table-cell">{{ $customers[$a?->customer_id] ?? '' }}</td>
                        <td class="num px-2 py-3 text-right font-semibold">{{ $a ? $money($a->amount) : '' }}@if ($a && ($a->grid_price === null || $a->amount > $a->grid_price))<span class="block text-xs text-warn">{{ __('выше сетки') }}</span>@endif</td>
                        <td class="hidden px-2 py-3 text-xs text-muted-foreground md:table-cell">{{ $users[$d->posted_by] ?? '' }}</td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('erp.tradein.print', $d) }}" target="_blank" class="text-xs text-primary">{{ __('Акт') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-10 text-center text-muted-foreground">{{ __('Актов trade-in пока нет. Принять телефон — «Новый выкуп» или F8 в Продаже') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-filament-panels::page>
