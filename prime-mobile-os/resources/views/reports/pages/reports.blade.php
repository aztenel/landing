<x-filament-panels::page>
@php
    $tabs = \App\Domain\Reports\Catalog\Report::TABS;
    $periods = \App\Domain\Reports\Services\Period::KEYS;
    $fmt = function ($v, string $kind) {
        if ($v === null || $v === '') return '—';
        return match ($kind) {
            'money' => \App\Support\Money\Money::kztMinor((int) $v)->format(),
            'usd' => '$'.number_format($v / 100, 2, ',', ' '),
            'int' => number_format((int) $v, 0, '', ' '),
            'pct' => str_replace('.', ',', (string) $v).'%',
            'num' => str_replace('.', ',', (string) $v),
            default => is_string($v) || is_numeric($v) ? $v : (string) $v,
        };
    };
    $num = fn (string $kind) => in_array($kind, ['money', 'usd', 'int', 'pct', 'num'], true);
    $card = 'rounded-2xl border border-border bg-card';
    $sel = 'h-10 rounded-lg border-input bg-card text-sm';
    $f = $report->filters();
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-end gap-3">
        <div class="mr-auto min-w-0">
            <p class="text-sm text-muted-foreground">{{ __('Отчёты') }} · {{ __($tabs[$report->tab()]) }}</p>
            <h1 class="text-2xl font-semibold tracking-tight">{{ $report->code() }}. {{ __($report->title()) }}</h1>
        </div>
        @if ($exportUrl)<a href="{{ $exportUrl }}" class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium hover:bg-muted"><x-ui::icon name="download" class="h-4 w-4" />Excel</a>@endif
        <button type="button" onclick="window.print()" class="hidden h-10 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium hover:bg-muted desk:inline-flex"><x-ui::icon name="printer" class="h-4 w-4" />PDF</button>
    </div>

    {{-- report picker by tabs --}}
    <div class="grid grid-cols-[minmax(0,1fr)] gap-2 desk:grid-cols-[minmax(0,1fr)]">
        <select wire:model.live="r" class="{{ $sel }} desk:hidden" aria-label="{{ __('Отчёт') }}">
            @foreach ($reports as $tab => $list)
                <optgroup label="{{ __($tabs[$tab]) }}">@foreach ($list as $x)<option value="{{ $x->key() }}">{{ $x->code() }}. {{ __($x->title()) }}</option>@endforeach</optgroup>
            @endforeach
        </select>
        <div class="hidden flex-wrap gap-x-6 gap-y-2 desk:flex">
            @foreach ($reports as $tab => $list)
                <div class="min-w-0">
                    <p class="mb-1 text-xs uppercase tracking-wide text-muted-foreground">{{ __($tabs[$tab]) }}</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($list as $x)
                            <button type="button" wire:click="$set('r', '{{ $x->key() }}')" class="h-8 rounded-full px-3 text-[13px] {{ $x->key() === $report->key() ? 'bg-foreground text-background' : 'border border-border bg-card hover:bg-muted' }}">{{ __($x->title()) }}</button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- filters (TZ 06 §1.2) --}}
    @if ($f)
        <div class="flex flex-wrap items-center gap-2">
            @if (in_array('period', $f, true))
                <select wire:model.live="period" class="{{ $sel }}">@foreach ($periods as $k => $l)<option value="{{ $k }}">{{ __($l) }}</option>@endforeach</select>
                @if ($period === 'custom')
                    <input type="date" wire:model.live="from" class="{{ $sel }}"><input type="date" wire:model.live="to" class="{{ $sel }}">
                @endif
            @endif
            @if (in_array('store', $f, true) && $stores->count() > 1)
                <select wire:model.live="store" class="{{ $sel }}"><option value="">{{ __('Все магазины') }}</option>@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
            @endif
            @if (in_array('dir', $f, true))
                <select wire:model.live="dir" class="{{ $sel }}"><option value="">{{ __('Всё') }}</option><option value="phones">{{ __('Электроника') }}</option><option value="accessories">{{ __('Аксессуары') }}</option></select>
            @endif
            @if (in_array('seller', $f, true))
                <select wire:model.live="seller" class="{{ $sel }}"><option value="">{{ __('Все продавцы') }}</option>@foreach ($sellers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            @endif
            @if (in_array('cond', $f, true))
                <select wire:model.live="cond" class="{{ $sel }}"><option value="">{{ __('Новые и б/у') }}</option><option value="new">{{ __('Новые') }}</option><option value="used">{{ __('Б/у') }}</option></select>
            @endif
            @if (in_array('group', $f, true))
                <select wire:model.live="group" class="{{ $sel }}"><option value="day">{{ __('По дням') }}</option><option value="week">{{ __('По неделям') }}</option><option value="month">{{ __('По месяцам') }}</option><option value="store">{{ __('По магазинам') }}</option></select>
            @endif
            <span class="text-sm text-muted-foreground">{{ $res['period']->label() }}@if (in_array('period', $f, true)) · {{ $res['period']->from->format('d.m.Y') }} – {{ $res['period']->to->format('d.m.Y') }}@endif</span>
        </div>
    @endif

    @foreach ($res['notes'] as $n)<p class="rounded-lg bg-warn/10 px-3 py-2 text-sm text-warn">{{ $n }}</p>@endforeach

    @if ($res['tiles'])
        <div class="grid grid-cols-2 gap-3 {{ [1 => 'desk:grid-cols-1', 2 => 'desk:grid-cols-2', 3 => 'desk:grid-cols-3', 4 => 'desk:grid-cols-4', 5 => 'desk:grid-cols-5'][min(5, count($res['tiles']))] }}">
            @foreach ($res['tiles'] as $t)
                <div class="{{ $card }} p-4">
                    <p class="text-sm text-muted-foreground">{{ $t['label'] }}</p>
                    <p class="num mt-1 text-xl font-semibold desk:text-2xl">{{ $fmt($t['value'], $t['kind']) }}</p>
                    @if ($t['hint'] ?? null)<p class="mt-0.5 text-xs text-muted-foreground">{{ $t['hint'] }}</p>@endif
                </div>
            @endforeach
        </div>
    @endif

    @php
        $rows = array_slice($res['rows'], 0, $rowsShown);
    @endphp
    <div class="{{ $card }} overflow-x-auto">
        @if ($res['rows'] === [])
            <p class="p-8 text-center text-sm text-muted-foreground">{{ __('За период данных нет. Попробуйте другой период или сбросьте фильтры.') }}</p>
        @else
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-card text-left text-xs text-muted-foreground"><tr class="border-b border-border">
                    @foreach ($res['columns'] as $k => $c)
                        <th class="whitespace-nowrap px-3 py-2 {{ $num($c['kind']) ? 'text-right' : '' }}"><button type="button" wire:click="sortBy('{{ $k }}')" class="inline-flex items-center gap-1 hover:text-foreground">{{ $c['label'] }}@if ($sort === $k)<span>{{ $desc ? '↓' : '↑' }}</span>@endif</button></th>
                    @endforeach
                </tr></thead>
                <tbody class="divide-y divide-border">
                    @foreach ($rows as $row)
                        <tr class="{{ $row['_class'] ?? '' }} {{ isset($row['_url']) ? 'cursor-pointer hover:bg-muted' : '' }}" @isset($row['_url']) onclick="window.location='{{ $row['_url'] }}'" @endisset>
                            @foreach ($res['columns'] as $k => $c)
                                <td class="px-3 py-2 {{ $num($c['kind']) ? 'num whitespace-nowrap text-right' : '' }}">{{ $fmt($row[$k] ?? null, $c['kind']) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if ($res['total'] ?? null)
                    <tfoot><tr class="border-t-2 border-border font-semibold">
                        @foreach ($res['columns'] as $k => $c)<td class="px-3 py-2 {{ $num($c['kind']) ? 'num whitespace-nowrap text-right' : '' }}">{{ $fmt($res['total'][$k] ?? null, $c['kind']) }}</td>@endforeach
                    </tr></tfoot>
                @endif
            </table>
            @if (count($res['rows']) > count($rows))
                <div class="border-t border-border p-3 text-center text-sm">
                    {{ __('Показано :a из :b', ['a' => count($rows), 'b' => count($res['rows'])]) }} ·
                    @if (! $all)<button type="button" wire:click="$set('all', true)" class="font-semibold text-primary">{{ __('Показать все') }}</button>@else{{ __('больше — только в Excel') }}@endif
                </div>
            @endif
        @endif
    </div>

    @foreach ($res['extra'] as $x)
        <div class="{{ $card }} overflow-x-auto">
            <h2 class="border-b border-border px-4 py-3 font-semibold">{{ $x['title'] }} <span class="font-normal text-muted-foreground">{{ count($x['rows']) }}</span></h2>
            @if ($x['rows'])
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground"><tr class="border-b border-border">@foreach ($x['columns'] as $c)<th class="px-3 py-2 {{ $num($c['kind']) ? 'text-right' : '' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
                    <tbody class="divide-y divide-border">
                        @foreach (array_slice($x['rows'], 0, 200) as $row)
                            <tr class="{{ $row['_class'] ?? '' }} {{ isset($row['_url']) ? 'cursor-pointer hover:bg-muted' : '' }}" @isset($row['_url']) onclick="window.location='{{ $row['_url'] }}'" @endisset>
                                @foreach ($x['columns'] as $k => $c)<td class="px-3 py-2 {{ $num($c['kind']) ? 'num whitespace-nowrap text-right' : '' }}">{{ $fmt($row[$k] ?? null, $c['kind']) }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="p-4 text-sm text-muted-foreground">{{ __('Нет') }}</p>
            @endif
        </div>
    @endforeach
</div>
</x-filament-panels::page>
