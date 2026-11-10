<x-filament-panels::page>
@php
    $btn = 'inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium hover:bg-muted';
    $primary = 'inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white';
    $input = 'rounded-lg border-input bg-background text-base';
    $card = 'rounded-xl border border-border bg-card';
    $money = fn ($t) => $t === null ? '—' : \App\Support\Money\Money::kztMinor((int) $t)->format();
    $kindNames = ['stock_new' => __('Новые (группа)'), 'stock_used' => __('Б/у'), 'accessories' => __('Аксессуар'), 'customer_debts' => __('Долг клиента'), 'supplier_debts' => __('Долг поставщику'),
        'bonuses' => __('Бонусы'), 'customers' => __('Клиент'), 'costs' => __('Себестоимость'), 'store' => __('Магазин')];
@endphp
<div class="space-y-4">
    @if ($readonly)<p class="rounded-lg bg-muted px-3 py-2 text-sm">{{ __('sfon отключён. Экран — только для чтения.') }}</p>@endif
    <div class="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1 text-sm" role="tablist">
        @foreach (\App\Filament\Migration\Pages\Migration::TABS as $k => $n)
            <button type="button" wire:click="$set('tab', '{{ $k }}')" class="h-10 shrink-0 px-3 {{ $tab === $k ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ __($n) }}</button>
        @endforeach
    </div>
    @if ($error)<p class="text-sm text-bad" role="alert">{{ $error }}</p>@elseif ($flash)<p class="text-sm text-ok" role="status">{{ $flash }}</p>@endif

    @if ($tab === 'checklist')
        <ol class="divide-y divide-border {{ $card }}">
            @foreach (\App\Domain\Migration\Services\DayX::STEPS as $step => $text)
                @php($s = $steps[$step] ?? null)
                <li class="flex items-center gap-3 px-4 py-3 text-sm">
                    <button type="button" wire:click="toggleStep('{{ $step }}')" @disabled($readonly) class="grid h-7 w-7 shrink-0 place-items-center rounded-full border {{ ($s?->status ?? '') === 'done' ? 'border-ok bg-ok text-white' : 'border-border' }}">@if (($s?->status ?? '') === 'done')<x-ui::icon name="check" class="h-4 w-4" />@endif</button>
                    <span class="w-9 shrink-0 font-semibold">{{ $step }}</span>
                    <span class="flex-1">{{ __($text) }}</span>
                    @if ($s?->done_at)<span class="text-xs text-muted-foreground">{{ \Carbon\Carbon::parse($s->done_at)->setTimezone($tz)->format('d.m H:i') }}{{ $s->note ? ' · '.$s->note : '' }}</span>@endif
                </li>
            @endforeach
        </ol>

    @elseif ($tab === 'files')
        <div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-2">
            <div class="{{ $card }} space-y-3 p-4">
                <h3 class="font-semibold">{{ __('API sfon') }}</h3>
                @if ($keySet)
                    <p class="text-sm text-muted-foreground">{{ __('Забирает магазины, справочник моделей и цветов, все телефоны в наличии (новые — группами, б/у — поштучно с фото). Живой учёт не меняется.') }}</p>
                    <button type="button" wire:click="pullSfon" wire:loading.attr="disabled" class="{{ $primary }}"><x-ui::icon name="download" class="h-4 w-4" />{{ __('Забрать из sfon') }}</button>
                    <span wire:loading wire:target="pullSfon" class="text-sm text-muted-foreground">{{ __('Забираем… это может занять несколько минут') }}</span>
                @else
                    <p class="rounded-lg bg-warn/10 px-3 py-2 text-sm text-warn">{{ __('Ключ API sfon не задан. Его вписывают в файл .env на сервере (строка SFON_API_KEY=…). В чат ключ не присылают.') }}</p>
                @endif
            </div>
            <div class="{{ $card }} space-y-3 p-4">
                <h3 class="font-semibold">{{ __('Excel из sfon') }}</h3>
                <select wire:model="kind" class="{{ $input }} w-full">@foreach ($kinds as $k => [$n])<option value="{{ $k }}">{{ __($n) }}</option>@endforeach</select>
                <input type="file" wire:model="file" accept=".xlsx,.csv" class="block w-full text-sm">
                <button type="button" wire:click="upload" wire:loading.attr="disabled" class="{{ $primary }}">{{ __('Загрузить') }}</button>
                <p class="text-xs text-muted-foreground">{{ __('Колонки находятся по названиям в первой строке (телефон, имя, сумма, IMEI, себестоимость…). Повторная загрузка не создаёт дублей.') }}</p>
            </div>
        </div>
        <div class="{{ $card }} overflow-x-auto">
            <table class="w-full text-sm"><thead class="text-left text-xs text-muted-foreground"><tr class="border-b border-border"><th class="px-3 py-2">{{ __('Когда') }}</th><th class="px-3 py-2">{{ __('Что') }}</th><th class="px-3 py-2">{{ __('Файл') }}</th><th class="px-3 py-2">{{ __('Итог') }}</th></tr></thead>
                <tbody class="divide-y divide-border">
                    @forelse ($runs as $r)
                        <tr><td class="num px-3 py-2">{{ $r->created_at ? \Carbon\Carbon::parse($r->created_at)->setTimezone($tz)->format('d.m H:i') : '' }}</td><td class="px-3 py-2">{{ $r->source === 'api' ? 'API' : __($kinds[$r->kind][0] ?? $r->kind) }}</td>
                            <td class="px-3 py-2">{{ $r->file_name }}</td><td class="px-3 py-2 {{ $r->status === 'failed' ? 'text-bad' : '' }}">{{ $r->error ?: collect((array) $r->totals)->map(fn ($v, $k) => $k.': '.$v)->implode(', ') }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="p-6 text-center text-muted-foreground">{{ __('Загрузок ещё не было') }}</td></tr>
                    @endforelse
                </tbody></table>
        </div>

    @elseif ($tab === 'fix')
        @include('migration.partials.quality')
        <div class="{{ $card }} overflow-x-auto">
            <table class="w-full text-sm"><thead class="text-left text-xs text-muted-foreground"><tr class="border-b border-border"><th class="px-3 py-2">{{ __('Тип') }}</th><th class="px-3 py-2">{{ __('Строка') }}</th><th class="px-3 py-2">{{ __('Проблема') }}</th><th class="px-3 py-2">{{ __('Себестоимость, ₸') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-border">
                    @forelse ($problems as $p)
                        @php($f = (array) $p->fields)
                        <tr wire:key="p{{ $p->id }}">
                            <td class="px-3 py-2">{{ $kindNames[$p->kind] ?? $p->kind }}</td>
                            <td class="px-3 py-2">{{ isset($f['variant_id']) && $f['variant_id'] ? $variants[$f['variant_id']]?->title() : ($f['name'] ?? ($p->data['modelName'] ?? $p->sfon_key)) }}@if (isset($f['count'])) · {{ $f['count'] }} {{ __('шт.') }}@endif @if ($f['price'] ?? null) · {{ $money($f['price']) }}@endif</td>
                            <td class="px-3 py-2 {{ $p->status === 'error' ? 'text-bad' : 'text-warn' }}">{{ $p->error ?: __('нет себестоимости') }}</td>
                            <td class="px-3 py-2">@if (in_array($p->kind, ['stock_new', 'stock_used'], true))<form wire:submit="saveCost({{ $p->id }})" class="flex gap-1"><input type="text" inputmode="numeric" wire:model="costs.{{ $p->id }}" class="{{ $input }} h-9 w-32" placeholder="{{ isset($f['cost']) ? number_format($f['cost'] / 100, 0, '', ' ') : '' }}"><button class="{{ $btn }} h-9">OK</button></form>@endif</td>
                            <td class="px-3 py-2"><button type="button" wire:click="skipRow({{ $p->id }})" class="text-xs text-muted-foreground hover:text-bad">{{ __('пропустить') }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-6 text-center text-ok">{{ __('Проблемных строк нет') }}</td></tr>
                    @endforelse
                </tbody></table>
        </div>

    @elseif ($tab === 'scan')
        <div class="flex flex-wrap items-center gap-2">
            @if ($stores->count() > 1)<select wire:model.live="storeId" class="{{ $input }} h-10">@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>@endif
            <form wire:submit="scan" class="flex flex-1 gap-2"><input type="text" inputmode="numeric" wire:model="imei" autofocus placeholder="{{ __('Сканируйте IMEI') }}" class="{{ $input }} h-12 min-w-0 flex-1 text-lg"><button class="{{ $primary }} h-12">{{ __('Скан') }}</button></form>
        </div>
        <p class="text-sm text-muted-foreground">{{ __('Отсканировано :a · лишних :b · не найдено :c', ['a' => $quality['scanned'], 'b' => $quality['extra'], 'c' => $quality['not_found']]) }}</p>
        @if ($pending)
            <div class="{{ $card }} space-y-3 p-4">
                <p class="font-semibold">IMEI <span class="num">{{ $pending['imei'] }}</span> — {{ __('какой это телефон?') }}</p>
                @if ($newGroups->isNotEmpty())
                    <p class="text-sm text-muted-foreground">{{ __('Новые (из sfon):') }}</p>
                    <div class="flex flex-wrap gap-2">@foreach ($newGroups as $g)<button type="button" wire:click="choose('new', {{ $g->id }})" class="{{ $btn }}">{{ $variants[$g->fields['variant_id']]?->title() }} <span class="text-muted-foreground">{{ app(\App\Domain\Migration\Services\DayX::class)->left($g) }} {{ __('ост.') }}</span></button>@endforeach</div>
                @endif
                @if ($usedCards->isNotEmpty())
                    <p class="text-sm text-muted-foreground">{{ __('Б/у без IMEI (из sfon):') }}</p>
                    <div class="grid grid-cols-[minmax(0,1fr)] gap-2 sm:grid-cols-2 desk:grid-cols-3">
                        @foreach ($usedCards as $u)
                            <button type="button" wire:click="choose('used', {{ $u->id }})" class="flex items-center gap-3 rounded-lg border border-border p-2 text-left text-sm hover:bg-muted">
                                @if ($u->fields['photos'][0] ?? null)<img src="{{ $u->fields['photos'][0] }}" alt="" class="h-12 w-12 rounded object-cover">@endif
                                <span>{{ $variants[$u->fields['variant_id']]?->title() }}<span class="block text-xs text-muted-foreground">{{ $money($u->fields['price'] ?? null) }} · {{ __('АКБ') }} {{ $u->fields['battery'] ?? '—' }}%</span></span>
                            </button>
                        @endforeach
                    </div>
                @endif
                <div class="space-y-2 border-t border-border pt-3">
                    <p class="text-sm font-medium">{{ __('Нет в sfon — «лишний» телефон') }}</p>
                    <input type="text" wire:model.live.debounce.300ms="extra.variant" placeholder="{{ __('Модель') }}" class="{{ $input }} w-full">
                    @foreach ($found as $v)<button type="button" wire:click="pickExtraVariant({{ $v->id }})" class="block text-left text-sm text-primary">{{ $v->title() }}</button>@endforeach
                    <div class="flex flex-wrap gap-2"><select wire:model="extra.condition" class="{{ $input }}"><option value="new">{{ __('Новый') }}</option><option value="used">{{ __('Б/у') }}</option></select>
                        <input type="text" inputmode="numeric" wire:model="extra.price" placeholder="{{ __('Цена, ₸') }}" class="{{ $input }} w-32"><input type="text" inputmode="numeric" wire:model="extra.cost" placeholder="{{ __('Себестоимость, ₸') }}" class="{{ $input }} w-40">
                        <button type="button" wire:click="saveExtra" class="{{ $btn }}">{{ __('Записать лишний') }}</button><button type="button" wire:click="$set('pending', null)" class="{{ $btn }}">{{ __('Отмена') }}</button></div>
                </div>
            </div>
        @endif
        <div class="{{ $card }} overflow-x-auto">
            <table class="w-full text-sm"><tbody class="divide-y divide-border">
                @foreach ($scans as $s)
                    <tr><td class="num px-3 py-2">{{ $s->imei }}</td><td class="px-3 py-2">{{ $variants[$s->variant_id]?->title() }}</td><td class="px-3 py-2">{{ $s->condition === 'used' ? __('б/у') : __('новый') }}{{ $s->extra ? ' · '.__('лишний') : '' }}</td>
                        <td class="px-3 py-2 text-right"><button type="button" wire:click="removeScan({{ $s->id }})" class="text-xs text-muted-foreground hover:text-bad">{{ __('удалить') }}</button></td></tr>
                @endforeach
            </tbody></table>
        </div>

    @elseif ($tab === 'post')
        @include('migration.partials.quality')
        <div class="{{ $card }} space-y-3 p-4">
            <h3 class="font-semibold">{{ __('Пересчёт денег на день X') }}</h3>
            @if ($stores->count() > 1)<select wire:model.live="storeId" class="{{ $input }}">@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>@endif
            <div class="grid grid-cols-[minmax(0,1fr)] gap-2 sm:grid-cols-2 desk:grid-cols-3">
                @foreach ($accounts as $a)
                    <label class="text-sm">{{ $a->name }} ({{ $a->currency === 'USD' ? '$' : '₸' }})<input type="text" inputmode="decimal" wire:model="money.{{ $a->id }}" class="{{ $input }} mt-1 w-full"></label>
                @endforeach
            </div>
            <p class="text-xs text-muted-foreground">{{ __('Касса — по купюрам, безнал — остаток из приложения банка на время стоп-операций. Проведение: телефоны по сканам, аксессуары, деньги, долги, бонусы — одним документом «Входящие остатки».') }}</p>
            <button type="button" wire:click="postVxo" wire:confirm="{{ __('Провести входящие остатки? Это начало живого учёта.') }}" @disabled($readonly) class="{{ $primary }}">{{ __('Провести входящие остатки') }}</button>
        </div>

    @elseif ($tab === 'reconcile')
        <div class="flex flex-wrap items-center gap-3">
            <p class="text-lg font-semibold">{{ __('Дней без расхождений: :n из 5', ['n' => $streak]) }}</p>
            @if ($keySet && ! $readonly)<button type="button" wire:click="reconcileNow" class="{{ $btn }} ml-auto">{{ __('Сверить сейчас') }}</button>@endif
        </div>
        <div class="space-y-2">
            @forelse ($runs as $r)
                <details class="{{ $card }} p-3 text-sm">
                    <summary class="flex cursor-pointer items-center gap-2"><span class="h-2.5 w-2.5 rounded-full {{ $r->discrepancies ? 'bg-bad' : 'bg-ok' }}"></span>{{ \Carbon\Carbon::parse($r->day)->format('d.m.Y') }} · {{ __('расхождений :n', ['n' => $r->discrepancies]) }}</summary>
                    <ol class="mt-2 list-decimal space-y-1 pl-6">@foreach ((array) json_decode((string) $r->details, true) as $d)<li>{{ $d['text'] }} <span class="text-muted-foreground">— {{ $d['cause'] }}</span></li>@endforeach</ol>
                </details>
            @empty
                <p class="text-sm text-muted-foreground">{{ __('Сверок ещё не было. Они идут каждую ночь в 03:00 после дня X.') }}</p>
            @endforelse
        </div>

    @elseif ($tab === 'archive')
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('IMEI, номер чека, телефон клиента или модель') }}" class="{{ $input }} w-full">
        @if ($found->isNotEmpty())
            <div class="{{ $card }} overflow-x-auto"><table class="w-full text-sm"><tbody class="divide-y divide-border">
                @foreach ($found as $a)<tr><td class="num px-3 py-2">{{ $a->occurred_at ? \Carbon\Carbon::parse($a->occurred_at)->format('d.m.Y') : '' }}</td><td class="px-3 py-2">{{ $a->number }}</td><td class="px-3 py-2">{{ $a->title }}</td><td class="num px-3 py-2">{{ $a->imei }}</td><td class="num px-3 py-2">{{ $a->customer_phone }}</td><td class="num px-3 py-2 text-right">{{ $money($a->amount) }}</td></tr>@endforeach
            </tbody></table></div>
        @endif
        <div class="{{ $card }}"><h3 class="border-b border-border px-4 py-3 font-semibold">{{ __('Продажи в sfon по месяцам') }}</h3>
            <ul class="divide-y divide-border text-sm">@forelse ($months as $m)<li class="flex gap-3 px-4 py-2"><span class="w-24">{{ $m->month }}</span><span class="flex-1 text-muted-foreground">{{ $m->n }} {{ __('строк') }}</span><span class="num">{{ $money((int) $m->s) }}</span></li>@empty<li class="p-4 text-muted-foreground">{{ __('Архив пуст. Загрузите «История продаж» во вкладке «Файлы и API».') }}</li>@endforelse</ul>
        </div>

    @elseif ($tab === 'off')
        <div class="{{ $card }} space-y-2 p-4 text-sm">
            <p class="flex items-center gap-2">{!! $auto['days14'] ? '✅' : '⬜' !!} {{ __('≥ 14 дней параллельной работы') }}</p>
            <p class="flex items-center gap-2">{!! $auto['streak5'] ? '✅' : '⬜' !!} {{ __('5 ночных сверок подряд без расхождений') }}</p>
            @foreach (['accept' => 'Приёмка принята, блокирующих замечаний нет', 'backup' => 'Финальная выгрузка sfon (JSON, Excel, скриншоты) сохранена в бэкап и открывается', 'links' => 'Ссылки в Instagram, 2ГИС, WhatsApp ведут на новый сайт', 'signed' => 'Кайрат подписал «Согласие на отключение sfon»'] as $k => $n)
                <label class="flex items-center gap-2"><input type="checkbox" wire:model="off.{{ $k }}" class="rounded border-input text-primary">{{ __($n) }}</label>
            @endforeach
            <p class="text-muted-foreground">{{ __('После отключения ночная сверка выключается, ключ API sfon больше не нужен.') }}</p>
            <button type="button" wire:click="switchOff" @disabled($readonly) class="{{ $primary }}">{{ __('Отключить сверку с sfon') }}</button>
        </div>
    @endif
</div>
</x-filament-panels::page>
