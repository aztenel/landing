<x-filament-panels::page>
@php
    $money = fn (?int $t) => $t === null ? '—' : \App\Support\Money\Money::kztMinor($t)->format();
    $big = fn (int $t) => $t >= 100_000_000_00 ? number_format($t / 100_000_000_00, 1, ',', ' ').' млн ₸' : \App\Support\Money\Money::kztMinor($t)->format();
    $sel = 'h-10 rounded-lg border-border bg-card py-0 text-sm';
    $supplierNames = $canSupplier ? $suppliers->pluck('name', 'id') : collect();
    $tz = \App\Support\Tenancy\CurrentOrganization::instance()->require()->timezone;
@endphp
<div class="space-y-4" x-data x-on:pm-scan.window="$wire.openByCode($event.detail.code)">
    <div class="flex flex-wrap items-center gap-2">
        <h1 class="mr-auto text-2xl font-semibold tracking-tight">{{ __('Склад') }}</h1>
        <a href="{{ $exportUrl }}" class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium hover:bg-muted"><x-ui::icon name="download" class="h-4 w-4" /> Excel</a>
        @if ($canReceive && \Illuminate\Support\Facades\Route::has('erp.intake.create'))
            <a href="{{ route('erp.intake.create') }}" class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white"><x-ui::icon name="plus" class="h-4 w-4" /> {{ __('Приход') }}</a>
        @endif
    </div>

    {{-- tabs with counters --}}
    <div class="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1 text-sm" role="tablist">
        @foreach (\App\Domain\Inventory\Queries\UnitList::TABS as $key => [$label])
            <button type="button" wire:click="$set('tab', '{{ $key }}')" role="tab" class="h-10 shrink-0 px-3 {{ $tab === $key ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">
                {{ __($label) }} <span class="num text-muted-foreground">{{ $counts[$key] }}</span>
            </button>
        @endforeach
        <a href="{{ \App\Filament\Stock\Pages\Accessories::getUrl() }}" class="flex h-10 shrink-0 items-center px-3 text-muted-foreground">{{ __('Аксессуары') }}</a>
    </div>

    {{-- summary tiles --}}
    <div class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border md:grid-cols-5">
        <div class="bg-card p-4"><div class="text-xs text-muted-foreground">{{ __('Телефонов в списке') }}</div><div class="num mt-1 text-2xl font-semibold">{{ $sum['n'] }}</div></div>
        @if ($canCost)<div class="bg-card p-4"><div class="text-xs text-muted-foreground">{{ __('По себестоимости') }}</div><div class="num mt-1 text-2xl font-semibold">{{ $big($sum['cost']) }}</div></div>@endif
        <div class="bg-card p-4"><div class="text-xs text-muted-foreground">{{ __('По ценам продажи') }}</div><div class="num mt-1 text-2xl font-semibold">{{ $big($sum['retail']) }}</div></div>
        <button type="button" wire:click="$set('imei', 'none')" class="bg-card p-4 text-left hover:bg-muted"><div class="text-xs text-muted-foreground">{{ __('Без проверки госбазы') }}</div><div class="num mt-1 text-2xl font-semibold {{ $noImei ? 'text-bad' : '' }}">{{ $noImei }}</div></button>
        <button type="button" wire:click="$set('age', 30)" class="bg-card p-4 text-left hover:bg-muted"><div class="text-xs text-muted-foreground">{{ __('Дольше 30 дней') }}</div><div class="num mt-1 text-2xl font-semibold {{ $old ? 'text-warn' : '' }}">{{ $old }}</div></button>
    </div>

    {{-- filters --}}
    <div class="flex flex-wrap items-center gap-2">
        <div class="relative min-w-[220px] flex-1 md:max-w-sm">
            <x-ui::icon name="search" class="pointer-events-none absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
            <input type="search" wire:model.live.debounce.300ms="q" data-pm-scan-target placeholder="{{ __('IMEI, PM-код или модель') }}" class="h-10 w-full rounded-lg border-border bg-card pl-9 text-base md:text-sm">
        </div>
        <select wire:model.live="model_id" class="{{ $sel }}"><option value="">{{ __('Модель') }}</option>@foreach ($models as $m)<option value="{{ $m->id }}">{{ $m->name_ru }}</option>@endforeach</select>
        <select wire:model.live="condition" class="{{ $sel }}"><option value="">{{ __('Состояние') }}</option><option value="new">{{ __('Новые') }}</option><option value="used">{{ __('Б/у') }}</option></select>
        <select wire:model.live="imei" class="{{ $sel }}"><option value="">{{ __('Госбаза') }}</option><option value="ok">{{ __('есть') }}</option><option value="none">{{ __('нет') }}</option><option value="stale">{{ __('устарела') }}</option></select>
        <select wire:model.live="age" class="{{ $sel }}"><option value="">{{ __('Срок на складе') }}</option><option value="30">{{ __('больше 30 дней') }}</option><option value="60">{{ __('больше 60 дней') }}</option><option value="90">{{ __('больше 90 дней') }}</option></select>
        @if ($stores->count() > 1)<select wire:model.live="store_id" class="{{ $sel }}"><option value="">{{ __('Все магазины') }}</option>@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>@endif
        @if ($canSupplier)<select wire:model.live="supplier_id" class="{{ $sel }}"><option value="">{{ __('Поставщик') }}</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>@endif
        @if ($canCost)<label class="flex h-10 items-center gap-2 text-sm"><input type="checkbox" wire:model.live="no_cost" class="h-4 w-4 rounded text-primary"> {{ __('без себестоимости') }}</label>@endif
        @unless ($canCost)<span class="ml-auto hidden items-center gap-1.5 text-xs text-muted-foreground md:flex"><x-ui::icon name="lock" class="h-3.5 w-3.5" /> {{ __('себестоимость видят владелец и бухгалтер') }}</span>@endunless
    </div>

    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>
    @elseif ($flash)<div class="text-sm text-ok" role="status">{{ $flash }}</div>@endif

    <div class="overflow-hidden rounded-xl border border-border bg-card">
        @if ($selected)
            <div class="flex flex-wrap items-center gap-4 bg-primary px-4 py-3 text-sm font-medium text-white">
                <span>{{ __('Выбрано :n', ['n' => count($selected)]) }}</span>
                <button type="button" disabled class="flex items-center gap-1.5 opacity-60" title="{{ __('Печать этикеток подключается с модулем печати') }}"><x-ui::icon name="printer" class="h-4 w-4" /> {{ __('Этикетка') }}</button>
                @if ($canImei)<button type="button" wire:click="markImei" wire:confirm="{{ __('Вы проверили эти IMEI в госбазе? Отметка ставится от вашего имени') }}" class="flex items-center gap-1.5"><x-ui::icon name="shield" class="h-4 w-4" /> {{ __('Отметить госбазу') }}</button>@endif
                @if ($canPrice)<button type="button" wire:click="$set('modal', 'price')" class="flex items-center gap-1.5"><x-ui::icon name="pencil" class="h-4 w-4" /> {{ __('Сменить цену') }}</button>@endif
                <button type="button" wire:click="$set('selected', [])" class="ml-auto opacity-80">{{ __('Снять выбор') }}</button>
            </div>
        @endif

        {{-- computer: table --}}
        <table class="hidden w-full text-sm desk:table">
            <thead class="border-b border-border text-left text-xs text-muted-foreground">
                <tr>
                    <th class="w-10 px-4 py-3"></th>
                    <th class="px-2 py-3">{{ __('Модель') }}</th>
                    <th class="px-2 py-3">IMEI</th>
                    @if ($canSupplier)<th class="px-2 py-3">{{ __('Поставщик') }}</th>@endif
                    <th class="px-2 py-3">{{ __('Статус') }}</th>
                    <th class="px-2 py-3">{{ __('Госбаза') }}</th>
                    @if ($stores->count() > 1)<th class="px-2 py-3">{{ __('Магазин') }}</th>@endif
                    @if ($canCost)<th class="px-2 py-3 text-right">{{ __('Себестоимость') }}</th>@endif
                    <th class="px-2 py-3 text-right">{{ __('Цена') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('На складе') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($units as $u)
                    @php
                        $check = $u->imei1 ? $u->latestImeiCheck() : null;
                        $fresh = $check && $check->result->value === 'registered' && $check->checked_at->gte(now()->subDays($maxAge));
                        $price = $u->sale_price ?? ($variantPrices[$u->variant_id] ?? null);
                        $days = $u->received_at ? (int) $u->received_at->diffInDays(now()) : null;
                        $url = route('erp.stock.units.show', $u->public_code ?? $u->id);
                    @endphp
                    <tr class="border-b border-border last:border-0 hover:bg-muted/40" wire:key="u{{ $u->id }}">
                        <td class="px-4 py-3"><input type="checkbox" value="{{ $u->id }}" wire:model.live="selected" class="h-4 w-4 rounded text-primary" aria-label="{{ __('Выбрать') }}"></td>
                        <td class="px-2 py-3"><a href="{{ $url }}" class="block">
                            <span class="block font-medium">{{ $u->variant?->productModel?->name_ru }}</span>
                            <span class="block text-xs text-muted-foreground">{{ collect([$u->variant?->memory?->name, $u->variant?->color?->name_ru, $u->condition?->value === 'used' ? 'б/у'.($u->grade ? ' '.$u->grade : '').($u->battery_percent ? ' · '.$u->battery_percent.'%' : '') : null])->filter()->implode(' · ') }}</span>
                        </a></td>
                        <td class="num px-2 py-3" title="{{ $u->imei1 }}">{{ $u->imei1 ? \App\Support\Imei\Imei::format($u->imei1) : $u->serial }}<span class="block text-xs text-muted-foreground">{{ $u->public_code }}</span></td>
                        @if ($canSupplier)<td class="px-2 py-3 text-muted-foreground">{{ $supplierNames[$u->supplier_id] ?? '—' }}</td>@endif
                        <td class="px-2 py-3"><span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-ok/10 text-ok' => $u->status?->value === 'in_stock', 'bg-warn/10 text-warn' => in_array($u->status?->value, ['reserved', 'ordered', 'preparing', 'in_transit']), 'bg-bad/10 text-bad' => in_array($u->status?->value, ['defective', 'on_check']), 'bg-muted text-muted-foreground' => in_array($u->status?->value, ['sold', 'written_off', 'returned_to_supplier'])])>{{ \App\Domain\Inventory\Enums\UnitStatus::labelOf($u->status) }}</span></td>
                        <td class="px-2 py-3 text-xs">@if (! $u->imei1)<span class="text-muted-foreground">—</span>@elseif ($fresh)<span class="text-ok">✓ {{ $check->checked_at->setTimezone($tz)->format('d.m') }}</span>@elseif ($check && $check->result->value === 'registered')<span class="text-warn">{{ __('устарела') }}</span>@else<span class="text-bad">{{ $check ? $check->result->label() : __('нет') }}</span>@endif</td>
                        @if ($stores->count() > 1)<td class="px-2 py-3 text-xs text-muted-foreground">{{ $u->location_type === 'store' ? ($storeNames[$u->location_id] ?? '') : __('в пути') }}</td>@endif
                        @if ($canCost)<td class="num px-2 py-3 text-right">{{ $u->cost_unknown ? __('нет') : $money((int) $u->cost_kzt) }}@if ($u->cost_currency === 'USD' && $u->cost_amount_orig)<span class="block text-xs text-muted-foreground">${{ number_format($u->cost_amount_orig / 100, 0, '', ' ') }}</span>@endif</td>@endif
                        <td class="num px-2 py-3 text-right font-semibold">{{ $money($price) }}</td>
                        <td class="num px-4 py-3 text-right {{ $days !== null && $days > 60 ? 'text-bad' : ($days !== null && $days > 30 ? 'text-warn' : 'text-muted-foreground') }}">{{ $days !== null ? $days.' '.__('дн.') : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- iPhone: cards --}}
        <div class="divide-y divide-border desk:hidden">
            @foreach ($units as $u)
                @php($price = $u->sale_price ?? ($variantPrices[$u->variant_id] ?? null))
                <a href="{{ route('erp.stock.units.show', $u->public_code ?? $u->id) }}" class="flex items-center gap-3 px-4 py-3" wire:key="m{{ $u->id }}">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-muted"><x-ui::icon name="phone" class="h-5 w-5 text-muted-foreground" /></div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium">{{ $u->variant?->productModel?->name_ru }} <span class="text-muted-foreground">{{ $u->variant?->memory?->name }}</span></div>
                        <div class="num truncate text-xs text-muted-foreground">…{{ substr((string) ($u->imei1 ?? $u->serial), -6) }} · {{ \App\Domain\Inventory\Enums\UnitStatus::labelOf($u->status) }}</div>
                    </div>
                    <div class="num shrink-0 font-semibold">{{ $money($price) }}</div>
                </a>
            @endforeach
        </div>

        @if ($units->isEmpty())
            <div class="p-10 text-center text-sm text-muted-foreground">
                @if ($counts['all'] === 0)
                    {{ __('На складе пока нет телефонов. Оприходуйте первую партию — сканером или с iPhone.') }}
                @else
                    {{ __('Ничего не нашлось.') }} <button type="button" wire:click="resetFilters" class="font-medium text-primary underline">{{ __('Сбросить фильтры') }}</button>
                @endif
            </div>
        @endif
    </div>
    <div>{{ $units->links() }}</div>

    @if ($modal === 'price')
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
            <div class="w-full rounded-t-2xl bg-card p-5 desk:w-[440px] desk:rounded-2xl" role="dialog">
                <h3 class="text-lg font-semibold">{{ __('Новая цена для :n шт.', ['n' => count($selected)]) }}</h3>
                <input type="text" inputmode="numeric" wire:model="newPrice" class="num mt-3 w-full rounded-lg border-input bg-background text-base" placeholder="489 000" autofocus>
                <p class="mt-2 text-xs text-muted-foreground">{{ __('Изменение больше лимита роли — только владелец.') }}</p>
                @if ($error)<p class="mt-2 text-sm text-bad">{{ $error }}</p>@endif
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('modal', null)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Отмена') }}</button>
                    <button type="button" wire:click="changePrice" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Сменить цену') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
