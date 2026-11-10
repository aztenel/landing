<x-filament-panels::page>
@php
    $input = 'w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary';
    $btn = 'inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium hover:bg-muted';
    $tenge = fn (int $t) => number_format(intdiv($t, 100), 0, '', ' ');
    $paid = collect($cart->get('payments'));
    $quick = $paid->count() === 1 ? $paid->first()['method'] : ($paid->count() > 1 ? 'split' : null);
    $print = (array) ($cart->get('print') ?? ['receipt' => true, 'warranty' => true, 'kk' => false]);
@endphp

<div class="pm-pos -mx-4 -mt-6 pb-[19rem] desk:mx-0 desk:-mt-2 desk:pb-0"
     x-data="pmPos"
     x-on:pm-scan.window="auto ? PM.autoAdd(() => $wire.scan($event.detail.code)) : $wire.scan($event.detail.code)"
     x-on:pm-catalog.window="$wire.set('catalog', $event.detail.value)"
     x-on:pm-focus-scan.window="$nextTick(() => document.getElementById('pos-scan')?.focus())"
     x-on:pm-sale-done.window="$refs.printReceipt?.checked && window.open($event.detail.url, 'pm-receipt', 'width=420,height=720')">

    <div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-[minmax(0,1fr)_420px]">
        {{-- ======================================================== middle: scan + tiles --}}
        <section class="min-w-0 space-y-4 px-4 desk:px-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="mr-auto text-2xl font-semibold tracking-tight">{{ __('Продажа') }}</h1>
                @if ($stores->count() > 1)
                    <select wire:change="switchStore($event.target.value)" class="h-10 rounded-lg border-input bg-card text-sm" aria-label="{{ __('Магазин') }}">
                        @foreach ($stores as $s)<option value="{{ $s->id }}" @selected($s->id === $store->id)>{{ $s->name }}</option>@endforeach
                    </select>
                @endif
                <button type="button" wire:click="goReturn" class="{{ $btn }} hidden desk:inline-flex"><x-ui::icon name="repeat" class="h-4 w-4" /> {{ __('Возврат') }} <x-ui::kbd>F10</x-ui::kbd></button>
                <button type="button" wire:click="tradeIn" class="{{ $btn }} hidden desk:inline-flex"><x-ui::icon name="download" class="h-4 w-4" /> {{ __('Trade-in') }} <x-ui::kbd>F8</x-ui::kbd></button>
                @if ($shift)
                    <button type="button" wire:click="openCloseShift" class="{{ $btn }}" title="{{ __('Закрыть смену') }}"><x-ui::icon name="lock" class="h-4 w-4" /><span class="hidden desk:inline">{{ __('Закрыть смену') }}</span></button>
                @endif
            </div>

            {{-- scan field: IMEI, barcode, PM-code or text to filter the tiles --}}
            <div class="flex items-center gap-3 rounded-xl border-2 border-primary bg-card px-4 py-2.5 shadow-sm">
                <x-ui::icon name="scan" class="h-6 w-6 shrink-0 text-muted-foreground" />
                <input id="pos-scan" type="text" data-pm-scan-target autocomplete="off" autofocus
                       wire:model.live.debounce.300ms="search"
                       x-on:keydown.enter.prevent="submitScan($el)"
                       placeholder="{{ __('Сканируйте IMEI или штрихкод') }}"
                       class="min-w-0 flex-1 border-0 bg-transparent p-0 text-lg focus:ring-0">
                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-lg bg-primary text-white desk:hidden" x-on:click="PM.scanner.open()" aria-label="{{ __('Камера') }}"><x-ui::icon name="camera" class="h-5 w-5" /></button>
                <label class="hidden items-center gap-2 text-sm text-muted-foreground sm:flex">
                    {{ __('Автодобавление 0,8 с') }}
                    <input type="checkbox" x-model="auto" x-on:change="saveAuto()" class="h-5 w-9 rounded-full border-input text-primary">
                </label>
            </div>
            @if ($error)
                <div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>
            @elseif ($flash)
                <div class="text-sm text-ok" role="status">{{ $flash }}</div>
            @endif

            @if ($orders->isNotEmpty() && $cart->isEmpty())
                <details class="rounded-xl border border-border bg-card px-4 py-2 text-sm">
                    <summary class="cursor-pointer py-1 font-medium">{{ __('Заказы магазина') }} · {{ $orders->count() }}</summary>
                    <div class="mt-2 divide-y divide-border">
                        @foreach ($orders as $o)
                            <div class="flex items-center gap-3 py-2" wire:key="o{{ $o->id }}">
                                <span class="num text-xs text-muted-foreground">{{ \App\Domain\Ledger\Models\Document::query()->whereKey($o->document_id)->value('number') }}</span>
                                <span class="flex-1 truncate">{{ \App\Domain\Counterparties\Models\Customer::query()->whereKey($o->customer_id)->value('name') }} · {{ $money((int) $o->price) }}</span>
                                @if ($o->status === 'overdue')<span class="rounded-full bg-bad/10 px-2 text-xs text-bad">{{ __('просрочен') }}</span>@endif
                                <button type="button" wire:click="sellOrder({{ $o->id }})" class="h-9 rounded-lg px-3 text-xs font-medium text-primary hover:bg-primary/5">{{ __('Продать по заказу') }}</button>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif

            {{-- tiles: computer only (TZ 04 Э3.8 p.8) --}}
            <div class="hidden desk:block">
                @if ($catalog === 'phones')
                    <div class="mb-3 flex flex-wrap gap-2 text-sm">
                        <button type="button" wire:click="$set('brand', '')" class="h-9 rounded-lg px-4 {{ $brand === '' ? 'bg-primary text-white' : 'border border-border bg-card' }}">{{ __('Все') }}</button>
                        @foreach ($brands as $b)
                            <button type="button" wire:click="$set('brand', @js($b))" class="h-9 rounded-lg px-4 {{ $brand === $b ? 'bg-primary text-white' : 'border border-border bg-card' }}">{{ $b }}</button>
                        @endforeach
                        <select wire:model.live="condition" class="h-9 rounded-lg border-border bg-card py-0 text-sm">
                            <option value="">{{ __('Новые и б/у') }}</option><option value="new">{{ __('Новые') }}</option><option value="used">{{ __('Б/у') }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3 xl:grid-cols-3 hd:grid-cols-4">
                        @forelse ($tiles as $tile)
                            @php($u = $tile['unit'])
                            <button type="button" wire:key="t{{ $tile['kind'] }}{{ $u->id }}"
                                    wire:click="addTile('unit', {{ $u->id }})"
                                    @class(['rounded-xl border bg-card p-3 text-left transition hover:shadow-md', 'border-primary ring-1 ring-primary' => $tile['state'] === 'in_cart', 'border-border' => $tile['state'] !== 'in_cart'])>
                                <div class="flex h-20 items-start justify-between rounded-lg bg-muted/60 p-2">
                                    <span class="rounded-md bg-card px-1.5 text-[11px] text-muted-foreground">
                                        {{ $u->condition?->value === 'used' ? __('б/у').($u->grade ? ' · '.$u->grade : '').($u->battery_percent ? ' · '.$u->battery_percent.'%' : '') : __('новый') }}
                                    </span>
                                    @if ($tile['count'] > 1)<span class="rounded-md bg-card px-1.5 text-[11px] font-medium">{{ $tile['count'] }} {{ __('шт.') }}</span>@endif
                                </div>
                                <div class="mt-2 truncate font-semibold">{{ $u->variant?->productModel?->name_ru }}</div>
                                <div class="truncate text-xs text-muted-foreground">{{ collect([$u->variant?->memory?->name, $u->variant?->color?->name_ru])->filter()->implode(' · ') }}</div>
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <span class="num text-xs text-muted-foreground">{{ $tile['count'] > 1 ? __('скан IMEI') : '…'.substr((string) $u->imei1, -6) }}</span>
                                    @if ($tile['state'] === 'in_cart')<span class="rounded-md border border-border px-1.5 text-[11px]">{{ __('в чеке') }}</span>
                                    @elseif ($tile['state'] === 'reserved')<span class="rounded-md bg-muted px-1.5 text-[11px] text-muted-foreground">{{ __('резерв') }}</span>@endif
                                </div>
                                <div class="num mt-1 text-lg font-semibold">{{ $tile['price'] !== null ? $money($tile['price']) : __('нет цены') }}</div>
                            </button>
                        @empty
                            <p class="col-span-full rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                                {{ \App\Support\Ui\Access::allows('stock.purchase') ? __('На складе магазина пока нет товаров. Сделайте приход') : __('На складе пусто. Сообщите администратору') }}
                            </p>
                        @endforelse
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-3 xl:grid-cols-3 hd:grid-cols-4">
                        @forelse ($accessoryTiles as $a)
                            <button type="button" wire:key="a{{ $a['id'] }}" wire:click="addTile('accessory', {{ $a['id'] }})" class="rounded-xl border border-border bg-card p-3 text-left hover:shadow-md">
                                <div class="flex h-14 items-center justify-center rounded-lg bg-muted/60"><x-ui::icon name="box" class="h-6 w-6 text-muted-foreground" /></div>
                                <div class="mt-2 line-clamp-2 text-sm font-medium">{{ $a['name'] }}</div>
                                <div class="text-xs text-muted-foreground">{{ __('осталось :n шт.', ['n' => $a['qty']]) }}</div>
                                <div class="num mt-1 font-semibold">{{ $a['price'] !== null ? $money($a['price']) : __('нет цены') }}</div>
                            </button>
                        @empty
                            <p class="col-span-full rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">{{ __('Аксессуаров в магазине нет') }}</p>
                        @endforelse
                    </div>
                @endif
            </div>
        </section>

        {{-- ======================================================== right: the check --}}
        <aside class="flex min-w-0 flex-col border-border bg-card desk:sticky desk:top-4 desk:max-h-[calc(100vh-6rem)] desk:rounded-xl desk:border">
            <div class="hidden items-center gap-1 border-b border-border px-4 pt-3 text-sm desk:flex" role="tablist">
                @foreach ($tabs as $tb)
                    <button type="button" wire:click="switchTab({{ $tb }})" role="tab" class="h-9 rounded-t-lg px-3 {{ $tb === $this->tab ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ __('Чек :n', ['n' => $tb]) }}</button>
                @endforeach
                @if (count($tabs) < \App\Domain\Pos\Services\CartService::MAX_TABS)
                    <button type="button" wire:click="newTab" class="h-9 px-2 text-muted-foreground" title="F7">+</button>
                @endif
            </div>

            <div class="flex-1 space-y-3 overflow-y-auto px-4 py-3">
                <div class="flex items-center justify-between empty:hidden">
                    <h2 class="hidden text-lg font-semibold desk:block">{{ __('Чек') }}</h2>
                    @if ($t['reserve_until'])
                        <span class="text-sm text-muted-foreground"><x-ui::icon name="clock" class="inline h-4 w-4" /> {{ __('резерв :m мин', ['m' => max(0, (int) now()->diffInMinutes($t['reserve_until'], false))]) }}</span>
                    @endif
                </div>

                {{-- customer --}}
                @if ($customer)
                    <div class="flex items-center gap-3 rounded-xl border border-border p-3">
                        <x-ui::avatar :name="$customer->name" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ $customer->name }}</div>
                            <div class="num truncate text-xs text-muted-foreground">{{ $customer->phone }} · {{ __('бонусы :b ₸', ['b' => number_format($bonuses, 0, '', ' ')]) }}</div>
                            @if ($debt && (int) $debt->balance > 0)
                                <div class="text-xs {{ $debt->nearest_due_date && $debt->nearest_due_date->lt(today()) ? 'text-bad' : 'text-warn' }}">{{ __('долг :d', ['d' => $money((int) $debt->balance)]) }}{{ $debt->nearest_due_date && $debt->nearest_due_date->lt(today()) ? ', '.__('просрочен') : '' }}</div>
                            @endif
                        </div>
                        <button type="button" wire:click="openCustomer" class="h-9 rounded-lg px-2 text-xs hover:bg-muted">{{ __('Сменить') }}</button>
                        <button type="button" wire:click="dropCustomer" class="h-9 rounded-lg px-2 text-xs hover:bg-muted" aria-label="{{ __('Убрать клиента') }}">×</button>
                    </div>
                @else
                    <button type="button" wire:click="openCustomer" class="flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-dashed border-border text-sm text-muted-foreground hover:bg-muted">
                        + {{ __('Клиент') }} <span class="hidden desk:inline">(<x-ui::kbd>F4</x-ui::kbd> {{ __('найти') }} · <x-ui::kbd>F3</x-ui::kbd> {{ __('новый') }})</span>
                    </button>
                @endif

                {{-- lines --}}
                @forelse ($t['lines'] as $line)
                    <div class="flex items-start gap-3 border-b border-border py-3" wire:key="l{{ $line['index'] }}-{{ $line['id'] }}">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-muted"><x-ui::icon :name="$line['kind'] === 'unit' ? 'phone' : 'box'" class="h-5 w-5 text-muted-foreground" /></div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ $line['title'] }}</div>
                            @if ($line['kind'] === 'unit')
                                <div class="num text-xs text-muted-foreground">{{ $line['imei'] }}
                                    @if ($line['imei_fresh'])<span class="ml-1 text-ok">✓ {{ __('госбаза') }}</span>
                                    @elseif ($line['imei_check'] === 'registered')<button type="button" wire:click="openImei({{ $line['id'] }})" class="ml-1 text-warn underline">{{ __('госбаза устарела') }}</button>
                                    @else<button type="button" wire:click="openImei({{ $line['id'] }})" class="ml-1 text-bad underline">{{ __('нет госбазы') }}</button>@endif
                                </div>
                            @else
                                <div class="mt-1 flex items-center gap-2 text-xs">
                                    <button type="button" wire:click="qty({{ $line['index'] }}, -1)" class="h-8 w-8 rounded-md border border-border">−</button>
                                    <span class="num w-8 text-center">{{ $line['qty'] }}</span>
                                    <button type="button" wire:click="qty({{ $line['index'] }}, 1)" class="h-8 w-8 rounded-md border border-border">+</button>
                                    <span class="text-muted-foreground">{{ __('шт.') }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="text-right">
                            <button type="button" wire:click="openDiscount({{ $line['index'] }})" class="num font-semibold hover:text-primary">{{ $money($line['amount']) }}</button>
                            @if ($line['discount'])<div class="num text-xs text-muted-foreground line-through">{{ $money($line['price'] * $line['qty']) }}</div>@endif
                            <button type="button" wire:click="removeLine({{ $line['index'] }})" class="mt-1 block w-full text-right text-xs text-muted-foreground hover:text-bad" aria-label="{{ __('Убрать') }}">{{ __('убрать') }}</button>
                        </div>
                    </div>
                @empty
                    <div class="py-10 text-center text-sm text-muted-foreground">
                        <x-ui::icon name="scan" class="mx-auto mb-2 h-10 w-10" />
                        {{ __('Наведите камеру на IMEI или штрихкод') }}
                        <div class="mt-3 desk:hidden"><button type="button" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white" x-on:click="PM.scanner.open()">{{ __('Сканировать') }}</button></div>
                    </div>
                @endforelse

                @if ($t['tradein'])
                    <div class="flex items-start gap-3 border-b border-border py-3">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-muted"><x-ui::icon name="swap" class="h-5 w-5 text-muted-foreground" /></div>
                        <div class="min-w-0 flex-1"><div class="font-medium">{{ __('Trade-in в зачёт') }}</div><div class="text-xs text-muted-foreground">{{ __('акт проводится вместе с чеком') }}</div></div>
                        <div class="text-right"><div class="num font-semibold">−{{ $money($t['tradein']['amount']) }}</div><button type="button" wire:click="dropTradeIn" class="text-xs text-muted-foreground hover:text-bad">{{ __('убрать') }}</button></div>
                    </div>
                @endif
                @if ($t['lines'] !== [])
                    <div class="hidden gap-2 desk:flex">
                        <button type="button" class="{{ $btn }} flex-1" disabled title="{{ __('Доставка подключается вместе со списком чеков') }}"><x-ui::icon name="truck" class="h-4 w-4" /> {{ __('Доставка') }}</button>
                        <select wire:change="setSource($event.target.value)" class="h-10 flex-1 rounded-lg border-border bg-card text-sm" aria-label="{{ __('Источник') }}">
                            <option value="">{{ __('Источник') }}</option>
                            @foreach (['instagram' => 'Instagram', 'whatsapp' => 'WhatsApp', 'site' => __('Сайт'), 'phone' => __('Звонок'), 'walk_in' => __('Пришёл сам')] as $k => $label)
                                <option value="{{ $k }}" @selected($cart->get('source') === $k)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            {{-- totals and payment: fixed bottom on iPhone (Э3.8 p.6) --}}
            <div class="fixed inset-x-0 bottom-16 z-20 space-y-2 border-t border-border bg-card px-4 pb-3 pt-3 shadow-[0_-8px_24px_rgba(0,0,0,.06)] desk:static desk:bottom-auto desk:shadow-none">
                @php($disc = $t['line_discounts'] + $t['check_discount'])
                <div class="flex items-center justify-between desk:hidden">
                    <button type="button" wire:click="openDiscount" class="text-sm text-muted-foreground">{{ __('Скидка') }} {{ $disc ? '−'.$money($disc) : '' }}</button>
                    <span class="num text-2xl font-bold">{{ $money($t['total']) }}</span>
                </div>
                <div class="hidden items-center justify-between text-sm desk:flex">
                    <button type="button" wire:click="openDiscount" class="text-muted-foreground hover:text-primary">{{ __('Скидка') }} <x-ui::kbd>F6</x-ui::kbd></button>
                    <span class="num">{{ $disc ? '−'.$money($disc) : '0 ₸' }}</span>
                </div>
                <div class="hidden items-center justify-between desk:flex">
                    <span class="text-lg font-semibold">{{ __('К оплате') }}</span>
                    <span class="num text-2xl font-bold">{{ $money($t['total']) }}</span>
                </div>
                <div class="grid grid-cols-5 gap-1.5 text-sm">
                    @foreach (['cash' => __('Нал'), 'card' => __('Карта'), 'kaspi_qr' => 'Kaspi', 'debt' => __('Долг'), 'split' => __('Сплит')] as $m => $label)
                        <button type="button" wire:click="quickPay('{{ $m }}')" @class(['h-10 rounded-lg font-medium', 'bg-primary text-white' => $quick === $m, 'border border-border bg-card hover:bg-muted' => $quick !== $m])>{{ $label }}</button>
                    @endforeach
                </div>
                @if ($paid->count() > 1)
                    <div class="num truncate text-xs text-muted-foreground">
                        {{ $paid->map(fn ($p) => (\App\Domain\Money\Enums\PaymentMethodCode::tryFrom($p['method'])?->label() ?? $p['method']).' '.$tenge((int) $p['amount']))->implode(' · ') }} · {{ __('сдача') }} {{ $money($t['change']) }}
                    </div>
                @endif
                <div class="hidden items-center gap-4 text-sm desk:flex">
                    <label class="flex items-center gap-1.5"><input type="checkbox" x-ref="printReceipt" @checked($print['receipt'] ?? true) wire:change="setPrint('receipt', $event.target.checked)" class="h-4 w-4 rounded text-primary"> {{ __('Чек') }}</label>
                    <label class="flex items-center gap-1.5"><input type="checkbox" @checked($print['warranty'] ?? true) wire:change="setPrint('warranty', $event.target.checked)" class="h-4 w-4 rounded text-primary"> {{ __('Гарантия') }}</label>
                    <label class="flex items-center gap-1.5"><input type="checkbox" @checked($print['kk'] ?? false) wire:change="setPrint('kk', $event.target.checked)" class="h-4 w-4 rounded text-primary"> {{ __('на казахском') }}</label>
                    <span class="num ml-auto text-muted-foreground">{{ __('сдача') }} {{ $money($t['change']) }}</span>
                </div>
                <button type="button" wire:click="f9" @disabled($blocker !== null) class="flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-primary text-lg font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40">
                    {{ __('Оплатить') }} {{ $money($t['total']) }} <span class="hidden opacity-70 desk:inline"><x-ui::kbd>F9</x-ui::kbd></span>
                </button>
                @if ($blocker)
                    <p class="text-center text-xs text-muted-foreground" data-pm-blocker>{{ $blocker['text'] }}
                        @isset($blocker['action'])<button type="button" wire:click="{{ $blocker['action'] }}" class="font-medium text-primary underline">{{ $blocker['label'] }}</button>@endisset
                    </p>
                @endif
            </div>
        </aside>
    </div>

    {{-- ======================================================== windows --}}
    @if ($modal)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center" x-on:keydown.escape.window="@if (! in_array($modal, ['shift-open'])) $wire.set('modal', null) @endif">
            <div class="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-card p-5 shadow-xl desk:w-[560px] desk:rounded-2xl" role="dialog" aria-modal="true">

                @if ($modal === 'customer')
                    <h3 class="text-lg font-semibold">{{ __('Клиент') }}</h3>
                    <input type="text" wire:model.live.debounce.250ms="customerQuery" autofocus placeholder="{{ __('Имя или телефон') }}" class="{{ $input }} mt-3">
                    <div class="mt-2 divide-y divide-border">
                        @foreach ($customers as $c)
                            <button type="button" wire:click="pickCustomer({{ $c->id }})" class="flex w-full items-center gap-3 py-2.5 text-left hover:bg-muted" wire:key="c{{ $c->id }}">
                                <x-ui::avatar :name="$c->name" /><span class="flex-1"><span class="block font-medium">{{ $c->name }}</span><span class="num block text-xs text-muted-foreground">{{ $c->phone }}</span></span>
                            </button>
                        @endforeach
                    </div>
                    <div class="mt-4 flex justify-between">
                        <button type="button" wire:click="openNewCustomer" class="text-sm font-medium text-primary">{{ __('Не нашли? Создать нового (F3)') }}</button>
                        <button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Закрыть') }}</button>
                    </div>

                @elseif ($modal === 'new-customer')
                    <h3 class="text-lg font-semibold">{{ __('Новый клиент') }}</h3>
                    <form wire:submit="createCustomer" class="mt-3 space-y-3">
                        <label class="block text-sm font-medium">{{ __('Телефон') }}<input type="tel" inputmode="tel" wire:model="newCustomer.phone" placeholder="+7 701 000 00 00" class="{{ $input }} num mt-1"></label>
                        @error('newCustomer.phone')<p class="text-sm text-bad">{{ $message }}</p>@enderror
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block text-sm font-medium">{{ __('Имя') }}<input type="text" wire:model="newCustomer.name" class="{{ $input }} mt-1"></label>
                            <label class="block text-sm font-medium">{{ __('Фамилия') }}<input type="text" wire:model="newCustomer.surname" class="{{ $input }} mt-1"></label>
                        </div>
                        @error('newCustomer.name')<p class="text-sm text-bad">{{ $message }}</p>@enderror
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model.live="newCustomer.consent" class="mt-0.5 h-5 w-5 rounded text-primary">
                            <span>{{ __('Клиент согласен на обработку персональных данных') }}<span class="block text-xs text-muted-foreground">{{ __('Прочитайте клиенту текст согласия или дайте QR') }}</span></span></label>
                        <select wire:model="newCustomer.consent_method" class="{{ $input }}">
                            <option value="oral">{{ __('Согласие устно') }}</option><option value="receipt">{{ __('Подпись на чеке') }}</option><option value="qr">{{ __('По QR') }}</option>
                        </select>
                        @error('newCustomer.consent')<p class="text-sm text-bad">{{ $message }}</p>@enderror
                        <div class="flex justify-end gap-2">
                            <button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Отмена') }}</button>
                            <button type="submit" @disabled(! $newCustomer['consent']) class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white disabled:opacity-40">{{ __('Сохранить') }}</button>
                        </div>
                    </form>

                @elseif ($modal === 'discount')
                    <h3 class="text-lg font-semibold">{{ $discountLine === null ? __('Скидка на чек') : __('Скидка на строку') }}</h3>
                    <div class="mt-3 flex gap-2">
                        <input type="text" inputmode="decimal" wire:model="discountValue" autofocus class="{{ $input }} num" placeholder="0">
                        <button type="button" wire:click="$toggle('discountPercent')" class="{{ $btn }} w-16">{{ $discountPercent ? '%' : '₸' }}</button>
                    </div>
                    @if ($discountLine === null)
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            @foreach ([__('постоянный клиент'), __('торг'), __('дефект упаковки'), __('акция')] as $r)
                                <button type="button" wire:click="$set('discountReason', @js($r))" class="h-8 rounded-full border px-3 {{ $discountReason === $r ? 'border-primary text-primary' : 'border-border' }}">{{ $r }}</button>
                            @endforeach
                        </div>
                        <input type="text" wire:model="discountReason" placeholder="{{ __('Причина') }}" class="{{ $input }} mt-2">
                    @endif
                    <p class="mt-2 text-xs text-muted-foreground">{{ __('Если скидка больше вашего лимита — при оплате появится кнопка «Запросить у владельца».') }}</p>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Отмена') }}</button>
                        <button type="button" wire:click="saveDiscount" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Применить') }}</button>
                    </div>

                @elseif ($modal === 'payment')
                    <div class="flex items-baseline justify-between"><h3 class="text-lg font-semibold">{{ __('К оплате') }}</h3><span class="num text-2xl font-bold">{{ $money($t['total']) }}</span></div>
                    <div class="mt-4 space-y-3">
                        @foreach ($payRows as $i => $row)
                            <div class="rounded-xl border border-border p-3" wire:key="p{{ $i }}">
                                <div class="flex gap-2">
                                    <select wire:model.live="payRows.{{ $i }}.method" class="min-w-0 flex-1 rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary">
                                        @foreach ($methods as $m)<option value="{{ $m->code }}">{{ $m->name_ru }}</option>@endforeach
                                    </select>
                                    <input type="text" inputmode="numeric" wire:model.live.debounce.300ms="payRows.{{ $i }}.amount" class="num w-36 shrink-0 rounded-lg border-input bg-background text-right text-base focus:border-primary focus:ring-primary" aria-label="{{ __('Сумма') }}">
                                    @if (count($payRows) > 1)<button type="button" wire:click="removePayRow({{ $i }})" class="h-10 px-2 text-muted-foreground" aria-label="{{ __('Убрать') }}">×</button>@endif
                                </div>
                                @if ($row['method'] === 'cash')
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                                        <label class="flex items-center gap-2">{{ __('Внесено') }} <input type="text" inputmode="numeric" wire:model.live.debounce.300ms="payRows.{{ $i }}.tendered" class="num w-32 rounded-lg border-input bg-background text-right text-base focus:border-primary focus:ring-primary"></label>
                                        @foreach ([5000, 10000, 20000] as $note)<button type="button" wire:click="addBanknote({{ $i }}, {{ $note }})" class="h-9 rounded-lg border border-border px-2 text-xs">+{{ number_format($note, 0, '', ' ') }}</button>@endforeach
                                        <button type="button" wire:click="addBanknote({{ $i }}, 0)" class="h-9 rounded-lg border border-border px-2 text-xs">{{ __('Без сдачи') }}</button>
                                        @php($ch = (int) preg_replace('/\D/', '', (string) $row['tendered']) - (int) preg_replace('/\D/', '', (string) $row['amount']))
                                        @if ($row['tendered'] !== '')<span class="num ml-auto font-semibold">{{ __('Сдача') }} {{ number_format(max(0, $ch), 0, '', ' ') }} ₸</span>@endif
                                    </div>
                                @elseif ($row['method'] === 'debt')
                                    <label class="mt-2 flex items-center gap-2 text-sm">{{ __('Дата возврата') }} <input type="date" wire:model="payRows.{{ $i }}.due_date" min="{{ now()->addDay()->toDateString() }}" class="w-44 rounded-lg border-input bg-background text-base"></label>
                                    @unless ($customer)<p class="mt-1 text-xs text-bad">{{ __('Для долга выберите клиента [F4]') }}</p>@endunless
                                @elseif ($row['method'] === 'bonus')
                                    <p class="mt-1 text-xs text-muted-foreground">{{ __('Доступно :b ₸', ['b' => number_format($bonuses, 0, '', ' ')]) }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="addPayRow" class="{{ $btn }}">+ {{ __('Ещё способ') }}</button>
                        <button type="button" wire:click="settle" class="{{ $btn }}">{{ __('Свести под расчёт') }}</button>
                    </div>
                    @php($left = $this->payLeft())
                    <p class="mt-3 text-sm font-medium {{ $left === 0 ? 'text-ok' : 'text-bad' }}">
                        {{ $left === 0 ? __('Сходится ✓') : ($left > 0 ? __('Баланс не сведён: не хватает :s ₸', ['s' => number_format($left, 0, '', ' ')]) : __('Оплат больше, чем чек, на :s ₸', ['s' => number_format(-$left, 0, '', ' ')])) }}
                    </p>
                    <label class="mt-3 block text-sm">{{ __('Номер фискального чека') }}<input type="text" wire:model="fiscal" class="{{ $input }} mt-1" placeholder="{{ __('можно позже из списка чеков') }}"></label>
                    @if ($error)<p class="mt-2 text-sm text-bad" role="alert">{{ $error }}</p>@endif
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Назад') }} <span class="hidden desk:inline"><x-ui::kbd>Esc</x-ui::kbd></span></button>
                        <button type="button" wire:click="pay" wire:loading.attr="disabled" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white disabled:opacity-50">{{ __('Провести') }} <span class="hidden desk:inline"><x-ui::kbd>F9</x-ui::kbd></span></button>
                    </div>

                @elseif ($modal === 'approval')
                    <h3 class="text-lg font-semibold">{{ __('Нужно разрешение владельца') }}</h3>
                    @if ($request && $request->status->value === 'pending')
                        <div wire:poll.3s="pollApproval" class="mt-3 rounded-xl bg-primary/5 p-4 text-sm">
                            {{ __('Заявка отправлена. Ждём ответа…') }}
                            <div class="mt-1 text-xs text-muted-foreground">{{ $request->headline }}</div>
                        </div>
                        <div class="mt-4 flex justify-end gap-2">
                            <button type="button" wire:click="cancelApproval" class="{{ $btn }}">{{ __('Отменить заявку') }}</button>
                        </div>
                    @elseif ($request && $request->status->value === 'rejected')
                        <div class="mt-3 rounded-xl bg-bad/5 p-4 text-sm text-bad">{{ __('Отклонено') }}{{ $request->decision_comment ? ': «'.$request->decision_comment.'»' : '' }}</div>
                        <div class="mt-4 flex justify-end"><button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Изменить чек') }}</button></div>
                    @else
                        <p class="mt-3 text-sm">{{ $error }}</p>
                        <label class="mt-3 block text-sm">{{ __('Комментарий для владельца') }}<input type="text" wire:model="approvalReason" class="{{ $input }} mt-1"></label>
                        <div class="mt-4 flex justify-end gap-2">
                            <button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Изменить чек') }}</button>
                            <button type="button" wire:click="requestApproval" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Запросить у владельца') }}</button>
                        </div>
                    @endif

                @elseif ($modal === 'done' && $done)
                    <div class="py-4 text-center">
                        <x-ui::icon name="check" class="mx-auto h-12 w-12 text-ok" />
                        <h3 class="mt-2 text-lg font-semibold">{{ __('Чек :n проведён', ['n' => $done->number]) }}</h3>
                        @php($lastCash = \App\Domain\Sales\Models\SalePayment::query()->where('document_id', $done->id)->where('method', 'cash')->value('change'))
                        @if ($lastCash)<div class="num mt-3 text-3xl font-bold">{{ __('Сдача') }} {{ $money((int) $lastCash) }}</div>@endif
                        <div class="mt-5 flex justify-center gap-2">
                            <a href="{{ route('erp.print.sale', $done) }}" target="_blank" class="{{ $btn }}"><x-ui::icon name="printer" class="h-4 w-4" /> {{ __('Чек') }}</a>
                            @if (\App\Domain\Sales\Models\SaleLine::query()->where('document_id', $done->id)->whereNotNull('stock_unit_id')->exists())<a href="{{ route('erp.print.warranty', $done) }}" target="_blank" class="{{ $btn }}"><x-ui::icon name="shield" class="h-4 w-4" /> {{ __('Гарантия') }}</a>@endif
                            <button type="button" wire:click="newCheck" autofocus class="h-10 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ __('Новый чек') }} <span class="hidden desk:inline"><x-ui::kbd>Enter</x-ui::kbd></span></button>
                        </div>
                    </div>
                    <div x-init="setTimeout(() => $wire.newCheck(), 10000)"></div>

                @elseif ($modal === 'imei')
                    <h3 class="text-lg font-semibold">{{ __('Проверка госбазы IMEI') }}</h3>
                    <p class="mt-2 text-sm text-muted-foreground">{{ __('Проверьте IMEI на сайте госбазы и отметьте результат.') }}</p>
                    <div class="mt-4 grid gap-2">
                        <button type="button" wire:click="saveImei('registered')" class="h-11 rounded-lg bg-ok/10 font-medium text-ok">{{ __('Зарегистрирован') }}</button>
                        <button type="button" wire:click="saveImei('not_registered')" class="h-11 rounded-lg bg-bad/10 font-medium text-bad">{{ __('Не зарегистрирован') }}</button>
                        <button type="button" wire:click="saveImei('blocked')" class="h-11 rounded-lg bg-bad/10 font-medium text-bad">{{ __('Заблокирован') }}</button>
                    </div>
                    <div class="mt-3 flex justify-end"><button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Закрыть') }}</button></div>

                @elseif ($modal === 'shift-open' || $modal === 'shift-close')
                    <h3 class="text-lg font-semibold">{{ $modal === 'shift-open' ? __('Открыть смену') : __('Закрыть смену') }} · {{ $store->name }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">{{ __('Пересчитайте наличные в каждой кассе.') }}</p>
                    <div class="mt-4 space-y-3">
                        @foreach ($registers as $acc)
                            <label class="block text-sm font-medium" wire:key="r{{ $acc->id }}">{{ $acc->name }}
                                @isset($expected[$acc->id])<span class="num font-normal text-muted-foreground"> · {{ __('должно быть') }} {{ \App\Support\Money\Money::of($expected[$acc->id], $acc->currency->value)->format() }}</span>@endisset
                                <input type="text" inputmode="decimal" wire:model="counted.{{ $acc->id }}" class="{{ $input }} num mt-1" placeholder="{{ __('пересчитали') }}">
                            </label>
                        @endforeach
                        <label class="block text-sm">{{ __('Комментарий (обязателен, если не сходится)') }}<input type="text" wire:model="shiftComment" class="{{ $input }} mt-1"></label>
                    </div>
                    @if ($error)<p class="mt-2 text-sm text-bad" role="alert">{{ $error }}</p>@endif
                    <div class="mt-4 flex items-center justify-end gap-2">
                        @if ($modal === 'shift-open')
                            <span class="mr-auto text-xs text-muted-foreground">{{ __('Без открытой смены продавать нельзя') }}</span>
                            <button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Позже') }}</button>
                            <button type="button" wire:click="doOpenShift" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Открыть смену') }}</button>
                        @else
                            <button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Отмена') }}</button>
                            <button type="button" wire:click="doCloseShift" wire:confirm="{{ __('Закрыть смену? Продажи остановятся до следующего открытия') }}" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Закрыть смену') }}</button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
