<x-filament-panels::page>
@php
    $input = 'mt-1 w-full rounded-lg border-input bg-background text-base';
    $cur = $head['currency'] === 'USD' ? '$' : '₸';
    $fmt = fn (int $minor) => \App\Support\Money\Money::of($minor, $head['currency'])->format();
@endphp
<div class="space-y-4 pb-40 desk:pb-0"
     x-data
     x-on:pm-scan.window="$event.detail.kind === 'barcode' && $event.detail.code.length !== 15 ? $wire.scanAccessory($event.detail.code) : $wire.scan($event.detail.code)"
     x-on:pm-focus-intake-scan.window="$nextTick(() => document.getElementById('intake-scan')?.focus())"
     x-on:pm-intake-bad.window="navigator.vibrate && navigator.vibrate([80, 60, 80])">

    @if ($doc)
        <div class="mx-auto max-w-xl rounded-2xl border border-border bg-card p-6 text-center">
            <x-ui::icon name="check" class="mx-auto h-12 w-12 text-ok" />
            <h2 class="mt-2 text-xl font-semibold">{{ __('Приход :n проведён', ['n' => $doc->number]) }}</h2>
            @if ($postedUnits->isNotEmpty())
                <p class="mt-2 text-sm text-muted-foreground">{{ __('Сфотографируйте телефоны для сайта — откройте паспорт:') }}</p>
                <div class="mt-3 flex flex-wrap justify-center gap-2">
                    @foreach ($postedUnits as $u)
                        <a href="{{ route('erp.stock.units.show', $u->public_code ?? $u->id) }}" class="num rounded-lg border border-border px-2.5 py-1.5 text-sm hover:bg-muted">{{ $u->public_code }}</a>
                    @endforeach
                </div>
            @endif
            <div class="mt-5 flex justify-center gap-2">
                <a href="{{ \App\Filament\Intake\Pages\Intakes::getUrl() }}" class="inline-flex h-10 items-center rounded-lg border border-border px-4 text-sm">{{ __('К списку приходов') }}</a>
                <button type="button" wire:click="again" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Новый приход') }}</button>
            </div>
        </div>
    @else
        {{-- 1. header --}}
        <section class="rounded-xl border border-border bg-card p-4">
            <div class="grid gap-3 sm:grid-cols-2 desk:grid-cols-4">
                @if ($stores->count() > 1)
                    <label class="text-sm font-medium">{{ __('Магазин') }}<select wire:model="head.store_id" class="{{ $input }}">@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
                @endif
                <label class="text-sm font-medium">{{ __('Поставщик') }}<select wire:model.live="head.supplier_id" class="{{ $input }}"><option value="">{{ __('Выберите') }}</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
                <label class="text-sm font-medium">{{ __('Валюта') }}<select wire:model.live="head.currency" class="{{ $input }}"><option value="KZT">₸</option><option value="USD">$ @if ($rate) · {{ __('курс') }} {{ rtrim(rtrim(number_format($rate, 2, ',', ' '), '0'), ',') }}@endif</option></select></label>
                <label class="text-sm font-medium">{{ __('№ накладной') }}<input type="text" wire:model="head.invoice" class="{{ $input }}"></label>
                @if ($canCost)
                    <label class="text-sm font-medium">{{ __('Оплата') }}<select wire:model.live="head.pay" class="{{ $input }}"><option value="credit">{{ __('в долг поставщику') }}</option><option value="paid">{{ __('оплачено сразу') }}</option><option value="partial">{{ __('частично') }}</option></select></label>
                    @if ($head['pay'] !== 'credit')
                        <label class="text-sm font-medium">{{ __('Со счёта') }}<select wire:model="head.account_id" class="{{ $input }}"><option value="">{{ __('Выберите') }}</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
                    @endif
                    @if ($head['pay'] === 'partial')
                        <label class="text-sm font-medium">{{ __('Оплачено, :c', ['c' => $cur]) }}<input type="text" inputmode="decimal" wire:model="head.paid" class="{{ $input }} num"></label>
                    @endif
                    @if ($head['pay'] !== 'paid')
                        <label class="text-sm font-medium">{{ __('Срок долга') }}<input type="date" wire:model="head.due" class="{{ $input }}"></label>
                    @endif
                @endif
            </div>
        </section>

        <div class="space-y-4">
            {{-- 2. batches --}}
            @foreach ($batches as $i => $b)
                @php($ok = collect($b['rows'])->whereNull('error')->count())
                <section wire:key="b{{ $i }}" wire:click="$set('active', {{ $i }})" @class(['rounded-xl border bg-card p-4', 'border-primary ring-1 ring-primary' => $active === $i, 'border-border' => $active !== $i])>
                    <div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-[360px_minmax(0,1fr)]">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between"><h3 class="font-semibold">{{ __('Партия :n', ['n' => $i + 1]) }}</h3>
                                @if (count($batches) > 1)<button type="button" wire:click.stop="removeBatch({{ $i }})" class="text-xs text-muted-foreground hover:text-bad">{{ __('удалить') }}</button>@endif</div>
                            <label class="block text-sm font-medium">{{ __('Модель') }}
                                <input type="text" wire:model.live.debounce.300ms="batches.{{ $i }}.search" x-on:input="$wire.set('batches.{{ $i }}.variant_id', null)" placeholder="{{ __('например 15 pro') }}" class="{{ $input }}">
                            </label>
                            @if ($active === $i && $variants->isNotEmpty())
                                <div class="max-h-48 overflow-y-auto rounded-lg border border-border">
                                    @foreach ($variants as $v)<button type="button" wire:click.stop="pickVariant({{ $i }}, {{ $v->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-muted">{{ $v->title() }}</button>@endforeach
                                </div>
                            @endif
                            <div class="grid grid-cols-2 gap-3">
                                <label class="text-sm font-medium">{{ __('Состояние') }}<select wire:model.live="batches.{{ $i }}.condition" class="{{ $input }}"><option value="new">{{ __('новый') }}</option><option value="used">{{ __('б/у') }}</option></select></label>
                                <label class="text-sm font-medium">{{ __('Ожидаем, шт.') }}<input type="number" min="0" wire:model.live="batches.{{ $i }}.expected" class="{{ $input }} num"></label>
                                @if ($canCost)<label class="text-sm font-medium">{{ __('Закуп, :c', ['c' => $cur]) }}<input type="text" inputmode="decimal" wire:model.live.debounce.400ms="batches.{{ $i }}.price" class="{{ $input }} num"></label>@endif
                                <label class="text-sm font-medium">{{ __('Розница, ₸') }}<input type="text" inputmode="numeric" wire:model.live.debounce.400ms="batches.{{ $i }}.sale_price" class="{{ $input }} num"></label>
                                @if ($b['condition'] === 'used')
                                    <label class="text-sm font-medium">{{ __('Грейд') }}<select wire:model="batches.{{ $i }}.grade" class="{{ $input }}"><option value="">—</option><option>A</option><option>B</option><option>C</option></select></label>
                                    <label class="text-sm font-medium">{{ __('Батарея, %') }}<input type="number" min="0" max="100" wire:model="batches.{{ $i }}.battery" class="{{ $input }} num"></label>
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center gap-3">
                                <div class="num text-3xl font-bold">{{ (int) $b['expected'] > 0 ? __(':a из :e', ['a' => $ok, 'e' => (int) $b['expected']]) : $ok.' '.__('шт.') }}</div>
                                @if ($active === $i)
                                    <input id="intake-scan" type="text" data-pm-scan-target autocomplete="off" x-on:keydown.enter.prevent="$el.value.trim() && ($wire.scan($el.value.trim()), $el.value = '')" placeholder="{{ __('Сканируйте IMEI') }}" class="h-11 min-w-0 flex-1 rounded-lg border-2 border-primary bg-background text-base">
                                    <button type="button" class="flex h-11 w-11 items-center justify-center rounded-lg bg-primary text-white desk:hidden" x-on:click.stop="PM.scanner.open()" aria-label="{{ __('Камера') }}"><x-ui::icon name="camera" class="h-5 w-5" /></button>
                                @endif
                            </div>
                            @if ($active === $i && $scanWarning && ! $scanError)
                                <p class="mt-2 rounded-lg bg-warn/10 px-3 py-2 text-sm" role="status">{{ $scanWarning }}</p>
                            @endif
                            @if ($active === $i && $scanError)
                                <p class="mt-2 rounded-lg bg-bad/10 px-3 py-2 text-sm text-bad" role="alert" data-pm-scan-error>{{ $scanError }}@if ($scanPassport) · <a href="{{ route('erp.stock.units.show', $scanPassport) }}" class="font-medium underline">{{ __('Открыть паспорт?') }}</a>@endif</p>
                            @endif
                            @if ($canCost && $b['variant_id'] && (float) str_replace(',', '.', (string) $b['price']) > 0 && (int) preg_replace('/\D/', '', (string) $b['sale_price']) > 0)
                                @php($unitCost = (int) round((float) str_replace(',', '.', (string) $b['price']) * ($cur === '$' ? $rate : 1)))
                                @php($margin = (int) preg_replace('/\D/', '', (string) $b['sale_price']) - $unitCost)
                                <p class="mt-2 text-sm text-muted-foreground" data-pm-margin>{{ __('Себестоимость :c ₸ · маржа :m ₸ на штуку', ['c' => number_format($unitCost, 0, '', ' '), 'm' => number_format($margin, 0, '', ' ')]) }}@if ($ok > 0) · {{ __('итог партии :t ₸', ['t' => number_format($unitCost * $ok, 0, '', ' ')]) }}@endif</p>
                            @endif
                            @if ($b['rows'])
                                <ol class="mt-3 max-h-72 divide-y divide-border overflow-y-auto rounded-lg border border-border text-sm">
                                    @foreach ($b['rows'] as $r => $row)
                                        <li class="flex items-center gap-3 px-3 py-2 {{ $row['error'] ? 'bg-bad/5' : '' }}" wire:key="r{{ $i }}-{{ $r }}">
                                            <span class="num w-6 text-xs text-muted-foreground">{{ $r + 1 }}</span>
                                            <span class="num flex-1">{{ preg_match('/^\d{15}$/', $row['imei1']) ? \App\Support\Imei\Imei::format($row['imei1']) : $row['imei1'] }}</span>
                                            @if ($row['error'])<span class="text-xs text-bad">✗ {{ $row['error'] }}</span>@else<span class="text-ok">✓</span>@endif
                                            <button type="button" wire:click.stop="removeRow({{ $i }}, {{ $r }})" class="text-muted-foreground hover:text-bad" aria-label="{{ __('Удалить') }}">×</button>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </div>
                    </div>
                </section>
            @endforeach
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="addBatch" class="h-10 rounded-lg border border-border bg-card px-4 text-sm font-medium">+ {{ __('Партия') }}</button>
                <details class="relative">
                    <summary class="flex h-10 cursor-pointer list-none items-center rounded-lg border border-border bg-card px-4 text-sm font-medium">{{ __('Импорт из Excel') }}</summary>
                    <div class="absolute z-10 mt-2 w-80 space-y-2 rounded-xl border border-border bg-card p-4 shadow-lg">
                        <a href="{{ route('erp.intake.template') }}" class="text-sm text-primary underline">{{ __('Скачать шаблон') }}</a>
                        <input type="file" wire:model="file" accept=".xlsx" class="block w-full text-sm">
                        <button type="button" wire:click="importFile" class="h-9 rounded-lg bg-primary px-3 text-sm text-white">{{ __('Загрузить') }}</button>
                        @error('file')<p class="text-xs text-bad">{{ $message }}</p>@enderror
                    </div>
                </details>
            </div>

            @if ($preview)
                <section class="rounded-xl border border-border bg-card">
                    <h3 class="border-b border-border px-4 py-3 font-semibold">{{ __('Предпросмотр файла') }} · {{ count($preview) }} {{ __('строк') }}
                        · <span class="text-ok">{{ __(':n ок', ['n' => collect($preview)->whereNull('error')->count()]) }}</span>@if (collect($preview)->whereNotNull('error')->isNotEmpty()) · <span class="text-bad">{{ __(':n с ошибкой', ['n' => collect($preview)->whereNotNull('error')->count()]) }}</span>@endif</h3>
                    <div class="max-h-80 overflow-y-auto">
                        @foreach ($preview as $p => $row)
                            <div class="flex items-center gap-3 border-b border-border px-4 py-2 text-sm {{ $row['error'] ? 'bg-bad/5' : '' }}" wire:key="p{{ $p }}">
                                <span class="num w-10 text-xs text-muted-foreground">{{ $row['line'] }}</span><span class="flex-1">{{ $row['title'] }}</span><span class="num">{{ $row['imei1'] }}</span>
                                @if ($row['error'])<span class="text-xs text-bad">{{ $row['error'] }}</span>@else<span class="text-ok">✓</span>@endif
                                <button type="button" wire:click="dropPreviewRow({{ $p }})" class="text-muted-foreground">×</button>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-end gap-2 p-3"><button type="button" wire:click="$set('preview', [])" class="h-9 rounded-lg border border-border px-3 text-sm">{{ __('Отмена') }}</button><button type="button" wire:click="usePreview" @disabled(collect($preview)->whereNotNull('error')->isNotEmpty()) title="{{ __('Исправьте ошибки в файле или удалите строки') }}" class="h-9 rounded-lg bg-primary px-4 text-sm text-white disabled:opacity-50">{{ __('Добавить в приход') }}</button></div>
                </section>
            @endif

            {{-- accessories --}}
            @if (\App\Support\Ui\Access::allows('stock.receive_acc') && $canCost)
                <section class="rounded-xl border border-border bg-card p-4">
                    <h3 class="font-semibold">{{ __('Аксессуары') }}</h3>
                    <input type="text" wire:model.live.debounce.300ms="accSearch" placeholder="{{ __('Название или скан штрихкода') }}" class="{{ $input }}">
                    @foreach ($accResults as $a)<button type="button" wire:click="addAccessory({{ $a->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-muted">{{ $a->name_ru }}</button>@endforeach
                    @foreach ($accessories as $ai => $a)
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm" wire:key="ac{{ $ai }}">
                            <span class="min-w-[160px] flex-1">{{ $accNames[$a['accessory_id']] ?? '' }}</span>
                            <input type="number" min="1" wire:model.live="accessories.{{ $ai }}.qty" class="num h-9 w-20 rounded-lg border-input text-sm" aria-label="{{ __('Кол-во') }}">
                            <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="accessories.{{ $ai }}.price" placeholder="{{ __('закуп') }}" class="num h-9 w-28 rounded-lg border-input text-sm">
                            <input type="text" inputmode="numeric" wire:model="accessories.{{ $ai }}.sale_price" placeholder="{{ __('розница ₸') }}" class="num h-9 w-28 rounded-lg border-input text-sm">
                            <button type="button" wire:click="removeAccessory({{ $ai }})" class="text-muted-foreground">×</button>
                        </div>
                    @endforeach
                </section>
            @endif
        </div>

        {{-- 3. totals, fixed at the bottom --}}
        <div class="fixed inset-x-0 bottom-16 z-20 border-t border-border bg-card px-4 py-3 shadow-[0_-8px_24px_rgba(0,0,0,.06)] desk:sticky desk:inset-x-auto desk:bottom-0 desk:rounded-xl desk:border">
            @unless ($canCost)<p class="mb-2 rounded-lg bg-warn/10 px-3 py-1.5 text-xs text-warn">{{ __('Себестоимость внесёт администратор. До этого телефоны нельзя продать.') }}</p>@endunless
            @foreach (array_merge($t['short'], $t['below']) as $w)<p class="text-xs text-warn">{{ $w }}</p>@endforeach
            @if ($error)<p class="mb-1 text-sm text-bad" role="alert">{{ $error }}</p>@endif
            <div class="flex flex-wrap items-center gap-4">
                <div class="text-sm">
                    {{ __('Телефонов :n', ['n' => $t['units']]) }} · {{ __('аксессуаров :n шт.', ['n' => $t['acc']]) }}
                    @if ($canCost) · {{ __('закуп') }} <span class="num font-semibold">{{ $fmt($t['cost']) }}</span>@endif
                    @if ($t['retail']) · {{ __('розница') }} <span class="num">{{ \App\Support\Money\Money::kztMinor($t['retail'])->format() }}</span>@endif
                </div>
                <button type="button" wire:click="askConfirm" class="ml-auto h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ __('Оприходовать') }}</button>
            </div>
        </div>

        @if ($confirm)
            <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
                <div class="w-full rounded-t-2xl bg-card p-5 desk:w-[480px] desk:rounded-2xl" role="dialog">
                    <h3 class="text-lg font-semibold">{{ __('Оприходовать :u телефонов и :a аксессуаров?', ['u' => $t['units'], 'a' => $t['acc']]) }}</h3>
                    @if ($canCost && $t['cost'])<p class="mt-2 text-sm">{{ __('На сумму') }} <span class="num font-semibold">{{ $fmt($t['cost']) }}</span>@if ($head['pay'] !== 'paid'), {{ __('долг поставщику до :d', ['d' => \Carbon\CarbonImmutable::parse($head['due'])->format('d.m.Y')]) }}@endif</p>@endif
                    <div class="mt-4 flex justify-end gap-2"><button type="button" wire:click="$set('confirm', false)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Назад') }}</button><button type="button" wire:click="post" wire:loading.attr="disabled" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Оприходовать') }}</button></div>
                </div>
            </div>
        @endif
    @endif
</div>
</x-filament-panels::page>
