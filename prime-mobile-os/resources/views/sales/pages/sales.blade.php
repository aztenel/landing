<x-filament-panels::page>
@php($money = fn ($t) => \App\Support\Money\Money::kztMinor((int) $t)->format())
<div class="grid grid-cols-[minmax(0,1fr)] gap-4 xl:grid-cols-[minmax(0,1fr)_440px]">
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="{{ __('Номер, IMEI, телефон или имя клиента') }}" class="h-10 min-w-[220px] flex-1 rounded-lg border-border bg-card text-base md:max-w-sm md:text-sm">
            <select wire:model.live="period" class="h-10 rounded-lg border-border bg-card py-0 text-sm"><option value="1">{{ __('Сегодня') }}</option><option value="7">{{ __('7 дней') }}</option><option value="30">{{ __('30 дней') }}</option><option value="all">{{ __('Всё время') }}</option></select>
            <select wire:model.live="filter" class="h-10 rounded-lg border-border bg-card py-0 text-sm"><option value="">{{ __('Все чеки') }}</option><option value="no_fiscal">{{ __('без фиск. чека') }}</option><option value="debt">{{ __('с долгом') }}</option><option value="discount">{{ __('со скидкой') }}</option><option value="returned">{{ __('с возвратом') }}</option></select>
        </div>
        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border text-left text-xs text-muted-foreground"><tr>
                    <th class="px-4 py-3">{{ __('Время') }}</th><th class="px-2 py-3">№</th><th class="hidden px-2 py-3 md:table-cell">{{ __('Продавец') }}</th><th class="px-2 py-3">{{ __('Клиент') }}</th><th class="hidden px-2 py-3 md:table-cell">{{ __('Оплата') }}</th><th class="px-4 py-3 text-right">{{ __('Сумма') }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($docs as $d)
                        @php($s = $sales[$d->id] ?? null)
                        <tr class="cursor-pointer border-b border-border last:border-0 hover:bg-muted/40 {{ $open?->id === $d->id ? 'bg-primary/5' : '' }}" wire:click="open({{ $d->id }})" wire:key="d{{ $d->id }}">
                            <td class="num px-4 py-3 text-xs text-muted-foreground">{{ $d->posted_at?->setTimezone($tz)->format('d.m H:i') }}</td>
                            <td class="num px-2 py-3">{{ $d->number }}
                                @if ($d->status->value === 'reversed')<span class="ml-1 rounded-full bg-muted px-2 text-xs">{{ __('сторно') }}</span>@endif
                                @if (isset($returned[$d->id]))<span class="ml-1 rounded-full bg-warn/10 px-2 text-xs text-warn">{{ __('возврат') }}</span>@endif
                                @if (! $d->fiscal_receipt_no && $d->status->value === 'posted')<span class="ml-1 text-xs text-muted-foreground">{{ __('без фиск.') }}</span>@endif</td>
                            <td class="hidden px-2 py-3 text-muted-foreground md:table-cell">{{ $users[$s?->seller_id] ?? '' }}</td>
                            <td class="px-2 py-3">{{ $customers[$s?->customer_id] ?? '—' }}</td>
                            <td class="hidden px-2 py-3 text-xs md:table-cell">{{ ($methods[$d->id] ?? collect())->map(fn ($p) => $p->method->label())->implode(', ') }}</td>
                            <td class="num px-4 py-3 text-right font-semibold">{{ $s ? $money($s->total) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-10 text-center text-muted-foreground">{{ __('Чеков за этот период нет') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $docs->links() }}</div>
    </div>

    <div>
        @if ($open && $openSale)
            <div class="space-y-3 rounded-xl border border-border bg-card p-4 text-sm xl:sticky xl:top-4">
                <div class="flex items-start justify-between"><div><h2 class="num text-lg font-semibold">{{ $open->number }}</h2><div class="text-xs text-muted-foreground">{{ $open->posted_at?->setTimezone($tz)->format('d.m.Y H:i') }} · {{ $users[$openSale->seller_id] ?? \App\Models\User::query()->find($openSale->seller_id)?->name }}</div></div>
                    <button type="button" wire:click="$set('openId', null)" class="text-muted-foreground" aria-label="{{ __('Закрыть') }}">×</button></div>
                @if ($openSale->customer_id)<div>{{ __('Клиент') }}: {{ \App\Domain\Counterparties\Models\Customer::query()->find($openSale->customer_id)?->name }}</div>@endif
                <div class="divide-y divide-border rounded-lg border border-border">
                    @foreach ($openLines as $l)
                        @php($u = $l->stock_unit_id ? \App\Domain\Inventory\Models\StockUnit::query()->find($l->stock_unit_id) : null)
                        <div class="flex gap-2 px-3 py-2">
                            <div class="min-w-0 flex-1"><div class="truncate">{{ $u ? $u->variant?->title() : \App\Domain\Catalog\Models\Accessory::query()->find($l->accessory_id)?->name_ru }}</div>
                                @if ($u)<div class="num text-xs text-muted-foreground">{{ $imei($u->imei1) }}</div>@endif
                                @if ($l->returned_qty)<div class="text-xs text-warn">{{ __('возвращено :n шт.', ['n' => $l->returned_qty]) }}</div>@endif</div>
                            <div class="num text-right">{{ $l->qty }} × {{ $money($l->price) }}<div class="font-semibold">{{ $money($l->amount) }}</div>
                                @if ($canProfit && $l->cost_kzt !== null)<div class="text-xs text-muted-foreground">{{ __('прибыль') }} {{ $money($l->amount - $l->cost_kzt) }}</div>@endif</div>
                        </div>
                    @endforeach
                </div>
                @if ($openSale->discount_total)<div class="flex justify-between"><span>{{ __('Скидка') }}</span><span class="num">−{{ $money($openSale->discount_total) }}</span></div>@endif
                <div class="flex justify-between text-base font-semibold"><span>{{ __('Итого') }}</span><span class="num">{{ $money($openSale->total) }}</span></div>
                @foreach ($openPayments as $p)<div class="flex justify-between text-xs text-muted-foreground"><span>{{ $p->method->label() }}</span><span class="num">{{ $money($p->amount) }}</span></div>@endforeach

                @if ($canFiscal && $open->status->value === 'posted')
                    <div class="flex gap-2"><input type="text" wire:model="fiscal" placeholder="{{ __('№ фискального чека') }}" class="h-9 min-w-0 flex-1 rounded-lg border-input bg-background text-sm"><button type="button" wire:click="saveFiscal" class="h-9 rounded-lg border border-border px-3 text-xs">{{ __('Сохранить') }}</button></div>
                @endif
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('erp.print.sale', $open) }}" target="_blank" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-xs"><x-ui::icon name="printer" class="h-4 w-4" /> {{ __('Чек') }}</a>
                    @if ($open->type->value === 'sale')
                        <a href="{{ route('erp.print.warranty', $open) }}" target="_blank" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-xs"><x-ui::icon name="shield" class="h-4 w-4" /> {{ __('Гарантия') }}</a>
                        @if (\App\Domain\Sales\Models\SalePayment::query()->where('document_id', $open->id)->where('method', 'debt')->exists())<a href="{{ route('erp.print.debt', $open) }}" target="_blank" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-xs">{{ __('Расписка о долге') }}</a>@endif
                    @endif
                    @if ($canReturn && $open->status->value === 'posted')<a href="{{ \App\Filament\Sales\Pages\Returns::getUrl(['sale' => $open->id]) }}" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-xs"><x-ui::icon name="repeat" class="h-4 w-4" /> {{ __('Возврат') }}</a>@endif
                    @if ($canReverse && $open->status->value === 'posted')<button type="button" wire:click="$set('reverseOpen', true)" class="inline-flex h-9 items-center rounded-lg px-3 text-xs text-bad hover:bg-bad/10">{{ __('Сторно') }}</button>@endif
                </div>
                @if ($reverseOpen)
                    <div class="space-y-2 rounded-lg bg-bad/5 p-3">
                        <select wire:model="reverseReason" class="w-full rounded-lg border-input bg-background text-sm">
                            @foreach (['input_error' => __('Ошибка ввода'), 'wrong_item' => __('Не тот товар'), 'wrong_amount' => __('Не та сумма'), 'duplicate' => __('Дубль'), 'other' => __('Другое')] as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                        </select>
                        <input type="text" wire:model="reverseText" placeholder="{{ __('Пояснение') }}" class="w-full rounded-lg border-input bg-background text-sm">
                        <button type="button" wire:click="reverse" class="h-9 w-full rounded-lg bg-bad text-sm font-semibold text-white">{{ __('Сторнировать чек') }}</button>
                    </div>
                @endif
                @if ($error)<p class="text-sm text-bad" role="alert">{{ $error }}</p>@endif
            </div>
        @else
            <p class="hidden rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground xl:block">{{ __('Выберите чек') }}</p>
        @endif
    </div>
</div>
</x-filament-panels::page>
