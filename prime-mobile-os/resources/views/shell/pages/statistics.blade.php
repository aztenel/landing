<x-filament-panels::page>
@php
    $m = fn (?int $t) => \App\Support\Money\Money::kztMinor((int) $t)->format();
    $short = function (int $t) { $v = $t / 100; return $v >= 1000000 ? str_replace('.', ',', rtrim(rtrim(number_format($v / 1000000, 2, '.', ''), '0'), '.')).' млн' : number_format($v, 0, '', ' '); };
    $badge = function ($now, $before, bool $units = false) {
        if ($before === null) return null;
        if ($units) { $d = $now - $before; return $d === 0 ? null : [($d > 0 ? '+' : '').$d, $d >= 0]; }
        if (! $before) return $now ? [__('новое'), true] : null;
        $pct = (int) round(($now - $before) * 100 / abs($before));
        return [($pct > 0 ? '+' : '').$pct.'%', $pct >= 0];
    };
    $card = 'rounded-2xl border border-border bg-card';
    $prevWord = $p->key === 'today' ? __('вчера') : __('прошлый период');
    $methodShort = ['cash' => 'Наличные', 'kaspi_qr' => 'Kaspi', 'kaspi_transfer' => 'Kaspi', 'card' => 'Карта', 'debt' => 'В долг'];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-end gap-3">
        <div class="mr-auto min-w-0">
            <p class="text-sm text-muted-foreground">{{ $dateLine }}@if ($stores->count() === 1) · {{ $stores->first()->name }}@endif</p>
            <h1 class="text-2xl font-semibold tracking-tight desk:text-[28px]">{{ $full ? __('Статистика') : __('Мои продажи') }}</h1>
        </div>
        @if ($stores->count() > 1)
            <select wire:model.live="store" class="h-10 rounded-lg border-input bg-card text-sm"><option value="">{{ __('Все магазины') }}</option>@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
        @endif
        <select wire:model.live="dir" class="h-10 rounded-lg border-input bg-card text-sm" aria-label="{{ __('Направление') }}">
            <option value="">{{ __('Всё') }}</option><option value="phones">{{ __('Электроника') }}</option><option value="accessories">{{ __('Аксессуары') }}</option>
        </select>
        <div class="flex items-center gap-2" x-data="{ open: false }">
            <div class="relative">
                <button type="button" x-on:click="open = ! open" class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium"><x-ui::icon name="calendar" class="h-4 w-4" />{{ $p->label() }}</button>
                <div x-cloak x-show="open" x-on:click.outside="open = false" class="absolute right-0 z-30 mt-1 w-56 rounded-xl border border-border bg-card p-1 shadow-lg">
                    @foreach (\App\Domain\Reports\Services\Period::KEYS as $k => $label)
                        @if ($k !== 'custom')<button type="button" wire:click="$set('period', '{{ $k }}')" x-on:click="open = false" class="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-muted {{ $p->key === $k ? 'font-semibold' : '' }}">{{ __($label) }}</button>@endif
                    @endforeach
                    <div class="border-t border-border p-2 text-sm">
                        <p class="mb-1 text-muted-foreground">{{ __('Свой период') }}</p>
                        <div class="flex gap-1"><input type="date" wire:model="from" class="w-1/2 min-w-0 rounded-lg border-input bg-background text-xs"><input type="date" wire:model="to" class="w-1/2 min-w-0 rounded-lg border-input bg-background text-xs"></div>
                        <button type="button" wire:click="$set('period', 'custom')" x-on:click="open = false" class="mt-1 h-8 w-full rounded-lg bg-primary text-xs font-semibold text-white">{{ __('Показать') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- tiles (Э2.1 п. 2) --}}
    <div class="grid grid-cols-2 gap-3 desk:grid-cols-4 desk:gap-4">
        @php
$b = $badge($t['revenue'], $prev['revenue']);
@endphp
        <div class="{{ $card }} col-span-2 p-4 desk:col-span-1 desk:p-5">
            <div class="flex items-center justify-between text-sm text-muted-foreground">{{ __('Выручка') }} @if ($b)<span class="rounded-md px-1.5 py-0.5 text-xs font-semibold {{ $b[1] ? 'bg-ok/10 text-ok' : 'bg-bad/10 text-bad' }}">{{ $b[0] }}</span>@endif</div>
            <p class="num mt-2 text-[28px] font-semibold tracking-tight desk:text-[32px]">{{ $m($t['revenue']) }}</p>
            <p class="mt-1 text-sm text-muted-foreground">{{ $prevWord }} {{ $m($prev['revenue']) }}@if ($t['returns']) · {{ __('возвраты') }} {{ $m($t['returns']) }}@endif</p>
        </div>
        @if ($profit)
            @php
$b = $badge($t['gross'], $prev['gross']);
@endphp
            <div class="{{ $card }} p-4 desk:p-5">
                <div class="flex items-center justify-between text-sm text-muted-foreground"><span title="{{ __('Валовая прибыль = выручка − себестоимость. Маржа = прибыль / выручка') }}">{{ __('Прибыль') }}</span> @if ($b)<span class="rounded-md px-1.5 py-0.5 text-xs font-semibold {{ $b[1] ? 'bg-ok/10 text-ok' : 'bg-bad/10 text-bad' }}">{{ $b[0] }}</span>@endif</div>
                <p class="num mt-2 text-2xl font-semibold tracking-tight desk:text-[32px]">{{ $m($t['gross']) }}</p>
                <p class="mt-1 text-sm text-muted-foreground">{{ __('маржа') }} {{ $t['margin'] !== null ? str_replace('.', ',', (string) $t['margin']).'%' : '—' }} · {{ $prevWord }} {{ $m($prev['gross']) }}</p>
            </div>
        @endif
        @php
$b = $badge($t['phones'], $prev['phones'], true);
@endphp
        <div class="{{ $card }} p-4 desk:p-5">
            <div class="flex items-center justify-between text-sm text-muted-foreground">{{ __('Продано') }} @if ($b)<span class="rounded-md px-1.5 py-0.5 text-xs font-semibold {{ $b[1] ? 'bg-ok/10 text-ok' : 'bg-bad/10 text-bad' }}">{{ $b[0] }}</span>@endif</div>
            <p class="num mt-2 text-2xl font-semibold tracking-tight desk:text-[32px]">{{ $t['phones'] }} {{ __('шт.') }}</p>
            <p class="mt-1 text-sm text-muted-foreground">{{ __('аксессуаров') }} {{ $t['accessories'] }} · {{ __('средний чек') }} {{ $m($t['avg_check']) }} · {{ __('чеков') }} {{ $t['checks'] }}</p>
        </div>
        @if ($cash)
            <a href="{{ \App\Filament\Money\Pages\Balance::getUrl() }}" class="{{ $card }} block p-4 desk:p-5">
                <div class="text-sm text-muted-foreground">{{ __('Деньги в кассах') }}</div>
                <p class="num mt-2 text-2xl font-semibold tracking-tight desk:text-[32px]">{{ $m($cash['total']) }}</p>
                <p class="mt-1 text-sm text-muted-foreground">{{ __('нал') }} {{ $short($cash['cash']) }} · {{ __('безнал') }} {{ $short($cash['non_cash']) }}@if ($cash['usd']) · ${{ number_format($cash['usd'] / 100, 0, '', ' ') }}@endif</p>
            </a>
        @endif
    </div>

    @if ($t['unknown_cost_count'] && $profit)
        <p class="rounded-lg bg-warn/10 px-3 py-2 text-sm text-warn">{{ __('В периоде :n продаж с неизвестной себестоимостью на :s — прибыль завышена.', ['n' => $t['unknown_cost_count'], 's' => $m($t['unknown_cost_amount'])]) }}</p>
    @endif

    @if ($pending->isNotEmpty())
        <div class="space-y-2 desk:hidden">
            @foreach ($pending as $r)
                <a href="{{ \App\Filament\Approvals\Pages\Requests::getUrl() }}" class="{{ $card }} flex items-center gap-3 p-4">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-bad"></span>
                    <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ $r->headline }}</span><span class="block truncate text-sm text-muted-foreground">{{ $r->summary }}</span></span>
                    <span class="text-sm font-semibold text-primary">{{ __('Решить') }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-[minmax(0,1fr)_380px]">
        {{-- chart (Э2.1 п. 3) --}}
        <div class="{{ $card }} p-4 desk:p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div><h2 class="text-lg font-semibold">{{ __('Выручка по :b', ['b' => ['day' => __('дням'), 'week' => __('неделям'), 'month' => __('месяцам')][$bucket]]) }}</h2>
                    <p class="text-sm text-muted-foreground">{{ count($bars) }} {{ ['day' => __('дн.'), 'week' => __('нед.'), 'month' => __('мес.')][$bucket] }}</p></div>
                <p class="text-sm text-muted-foreground">{{ __('в среднем :s в день', ['s' => $short($avgDay).' ₸']) }}</p>
            </div>
            @if (array_sum(array_column($bars, 'revenue')) === 0)
                <div class="mt-6 grid h-48 place-items-center rounded-xl bg-muted text-center text-sm text-muted-foreground">
                    <div>{{ __('Здесь будет выручка по дням.') }} <a href="{{ \App\Filament\Pos\Pages\Sale::getUrl() }}" class="mt-2 block font-semibold text-primary">{{ __('Открыть Продажу') }}</a></div>
                </div>
            @else
                <div class="mt-6 flex h-56 items-end gap-1 overflow-x-auto desk:gap-2">
                    @foreach ($bars as $i => $bar)
                        <a href="{{ \App\Filament\Sales\Pages\Sales::getUrl() }}" class="group flex h-full min-w-[18px] flex-1 flex-col items-center justify-end gap-1"
                           title="{{ $bar['date']->format('d.m') }}: {{ $m($bar['revenue']) }}@if ($profit), {{ __('прибыль') }} {{ $m($bar['gross']) }}@endif, {{ $bar['checks'] }} {{ __('чеков') }}">
                            <span class="w-full rounded-t-md {{ $i === count($bars) - 1 ? 'bg-primary' : 'bg-primary/80' }} group-hover:bg-primary" style="height: {{ max(2, (int) round(max(0, $bar['revenue']) * 100 / $barMax)) }}%"></span>
                            <span class="num text-[11px] text-muted-foreground {{ $i === count($bars) - 1 ? 'font-semibold text-foreground' : '' }}">{{ $bar['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- payments and sellers (Э2.1 п. 4) --}}
        <div class="{{ $card }} p-4 desk:p-6">
            <h2 class="text-lg font-semibold">{{ __('Оплаты') }} <span class="font-normal text-muted-foreground">· {{ mb_strtolower($p->label()) }}</span></h2>
            @php
$payMax = max(1, (int) $payments->max('amount'));
@endphp
            <div class="mt-3 space-y-3">
                @forelse ($payments->take(6) as $method => $row)
                    <div>
                        <div class="flex justify-between text-sm"><span>{{ \App\Domain\Reports\Services\Metrics::methodLabel($method) }}</span><span class="num font-semibold">{{ $m($row['amount']) }}</span></div>
                        <div class="mt-1.5 h-1.5 rounded-full bg-muted"><div class="h-1.5 rounded-full {{ $method === 'debt' ? 'bg-warn' : 'bg-primary' }}" style="width: {{ max(2, (int) round(max(0, $row['amount']) * 100 / $payMax)) }}%"></div></div>
                    </div>
                @empty
                    <p class="text-sm text-muted-foreground">{{ __('Оплат за период нет') }}</p>
                @endforelse
            </div>
            @if ($full && $sellers->isNotEmpty())
                <h3 class="mt-5 border-t border-border pt-4 text-sm text-muted-foreground">{{ __('Продавцы') }}</h3>
                <ul class="mt-2 space-y-2">
                    @foreach ($sellers->take(6) as $id => $row)
                        @php
$name = $sellerNames[$id] ?? '—';
@endphp
                        <li class="flex items-center gap-3 text-sm">
                            <span class="grid h-8 w-8 place-items-center rounded-full bg-muted text-xs font-semibold">{{ mb_strtoupper(collect(explode(' ', $name))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('')) }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $name }} <span class="text-muted-foreground">{{ $row['checks'] }} {{ __('чеков') }}</span></span>
                            <span class="num text-right font-semibold">{{ $m($row['revenue']) }}@if ($profit)<span class="block text-xs font-normal text-muted-foreground">{{ __('прибыль') }} {{ $m($row['gross']) }}</span>@endif</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- last sales (Э2.1 п. 5) --}}
        <div class="{{ $card }}">
            <div class="flex items-center justify-between px-4 pt-4 desk:px-6 desk:pt-5"><h2 class="text-lg font-semibold">{{ __('Последние продажи') }}</h2>
                <a href="{{ \App\Filament\Sales\Pages\Sales::getUrl() }}" class="inline-flex items-center gap-1 text-sm text-muted-foreground">{{ __('Все продажи') }} <x-ui::icon name="arrow" class="h-4 w-4" /></a></div>
            <ul class="mt-2 divide-y divide-border">
                @forelse ($recent as $r)
                    @php
$ls = $lines[$r->id] ?? collect();
@endphp
                    @php
$first = $ls->first();
@endphp
                    @php
$v = $first?->variant_id ? $variants[$first->variant_id] ?? null : null;
@endphp
                    @php
$ms = ($pays[$r->id] ?? collect())->pluck('method')->unique();
@endphp
                    <li class="flex items-center gap-3 px-4 py-3 desk:px-6">
                        <span class="num w-12 shrink-0 text-sm text-muted-foreground">{{ \Carbon\Carbon::parse($r->occurred_at)->setTimezone($tz)->format('H:i') }}</span>
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-muted"><x-ui::icon :name="$first?->line_type === 'unit' ? 'phone' : 'box'" class="h-5 w-5 text-muted-foreground" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $v ? trim($v->productModel?->name_ru.' '.$v->memory?->name) : ($first?->name_ru ?? '—') }}@if ($ls->count() > 1) + {{ $ls->count() - 1 }}@endif</span>
                            <span class="block truncate text-sm text-muted-foreground">{{ $v?->color?->name_ru }}@if ($first?->condition) · {{ $first->condition === 'used' ? __('б/у') : __('новый') }}@endif</span>
                        </span>
                        @if ($full)<span class="hidden w-28 truncate text-sm sm:block">{{ $sellerNames[$r->seller_id] ?? '' }}</span>@endif
                        <span class="hidden shrink-0 rounded-md px-2 py-0.5 text-xs sm:inline {{ $ms->contains('debt') ? 'bg-warn/10 text-warn' : 'border border-border' }}">{{ $ms->count() > 1 ? __('Сплит') : __($methodShort[$ms->first()] ?? \App\Domain\Reports\Services\Metrics::methodLabel((string) $ms->first())) }}</span>
                        <span class="num w-28 shrink-0 text-right font-semibold">{{ $m((int) $r->total) }}</span>
                    </li>
                @empty
                    <li class="px-6 py-8 text-center text-sm text-muted-foreground">{{ __('Продаж пока нет. Первая продажа — в разделе «Продажа»') }}</li>
                @endforelse
            </ul>
        </div>

        {{-- to do (Э2.1 п. 6) --}}
        @if ($todo !== null)
            <div class="{{ $card }} p-4 desk:p-6">
                <h2 class="text-lg font-semibold">{{ __('Нужно сделать') }}</h2>
                @if ($todo)
                    <ul class="mt-2 divide-y divide-border">
                        @foreach ($todo as $item)
                            <li class="flex items-center gap-3 py-3">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ ['bad' => 'bg-bad', 'warn' => 'bg-warn', 'muted' => 'bg-muted-foreground/50', 'ok' => 'bg-ok'][$item['color']] }}"></span>
                                <span class="min-w-0 flex-1"><span class="block font-medium">{{ $item['title'] }}</span><span class="block text-sm text-muted-foreground">{{ $item['text'] }}</span></span>
                                <a href="{{ $item['url'] }}" class="inline-flex h-9 shrink-0 items-center rounded-lg border border-border px-3 text-sm font-medium hover:bg-muted">{{ $item['button'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-3 flex items-center gap-2 text-ok"><x-ui::icon name="check" class="h-5 w-5" />{{ __('Всё в порядке') }}</p>
                @endif
            </div>
        @endif
    </div>
</div>
</x-filament-panels::page>
