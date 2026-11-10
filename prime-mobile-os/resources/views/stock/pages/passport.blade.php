<x-filament-panels::page>
@php
    $money = fn (?int $t) => $t === null ? '—' : \App\Support\Money\Money::kztMinor($t)->format();
    $btn = 'inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium hover:bg-muted';
    $status = $unit->status?->value;
    $tabs = ['history' => __('История'), 'imei' => __('Госбаза'), 'prices' => __('Цены')]
        + ($unit->condition?->value === 'used' ? ['used' => __('Б/у')] : [])
        + ($canCost ? ['cost' => __('Себестоимость')] : [])
        + ['site' => __('Сайт')];
@endphp
<div class="space-y-4">
    <a href="{{ \App\Filament\Stock\Pages\Phones::getUrl() }}" class="inline-flex items-center gap-1 text-sm text-muted-foreground"><x-ui::icon name="chev-left" class="h-4 w-4" /> {{ __('Склад') }}</a>

    <div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-[280px_minmax(0,1fr)]">
        <div class="flex aspect-square items-center justify-center rounded-2xl bg-muted desk:aspect-auto desk:h-72">
            <x-ui::icon name="phone" class="h-16 w-16 text-muted-foreground" />
        </div>
        <div class="space-y-3">
            <div class="flex flex-wrap items-start gap-3">
                <div class="mr-auto">
                    <h1 class="text-2xl font-semibold tracking-tight">{{ $unit->variant?->title() }}</h1>
                    <div class="mt-1 text-sm text-muted-foreground">
                        {{ $unit->condition?->value === 'used' ? __('б/у').($unit->grade ? ' · '.$unit->grade : '').($unit->battery_percent ? ' · '.__('батарея').' '.$unit->battery_percent.'%' : '') : __('новый') }}
                        · <span class="num">{{ $unit->public_code }}</span> · {{ $store }}{{ $days !== null ? ' · '.__('на складе :d дн.', ['d' => $days]) : '' }}
                    </div>
                </div>
                <span @class(['rounded-full px-3 py-1 text-sm font-medium', 'bg-ok/10 text-ok' => $status === 'in_stock', 'bg-warn/10 text-warn' => in_array($status, ['reserved', 'ordered', 'preparing', 'in_transit']), 'bg-bad/10 text-bad' => in_array($status, ['defective', 'on_check']), 'bg-muted text-muted-foreground' => in_array($status, ['sold', 'written_off', 'returned_to_supplier'])])>{{ \App\Domain\Inventory\Enums\UnitStatus::labelOf($unit->status) }}</span>
            </div>

            <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                @foreach (['IMEI 1' => $unit->imei1, 'IMEI 2' => $unit->imei2, __('Серийный') => $unit->serial] as $label => $value)
                    @if ($value)
                        <div class="flex items-center gap-2"><dt class="w-20 text-muted-foreground">{{ $label }}</dt><dd class="num" x-data><span>{{ $value }}</span>
                            <button type="button" class="ml-1 text-muted-foreground" x-on:click="navigator.clipboard && navigator.clipboard.writeText(@js($value))" aria-label="{{ __('Копировать') }}"><x-ui::icon name="copy" class="inline h-3.5 w-3.5" /></button></dd></div>
                    @endif
                @endforeach
            </dl>

            <div class="flex flex-wrap items-end gap-6">
                <div><div class="text-xs text-muted-foreground">{{ __('Цена') }}</div><div class="num text-3xl font-bold">{{ $money($price) }}</div></div>
                @if ($canCost)
                    <div><div class="text-xs text-muted-foreground">{{ __('Себестоимость') }}</div><div class="num text-lg font-semibold">{{ $unit->cost_unknown ? __('не внесена') : $money((int) $unit->cost_kzt) }}</div></div>
                    @if ($price && ! $unit->cost_unknown)<div><div class="text-xs text-muted-foreground">{{ __('Наценка') }}</div><div class="num text-lg font-semibold">{{ $money($price - (int) $unit->cost_kzt) }} <span class="text-sm text-muted-foreground">{{ $unit->cost_kzt ? round(($price - $unit->cost_kzt) * 100 / $unit->cost_kzt) : 0 }}%</span></div></div>@endif
                @endif
                @if ($canSupplier && $supplier)<div><div class="text-xs text-muted-foreground">{{ __('Поставщик') }}</div><div class="font-medium">{{ $supplier }}</div></div>@endif
            </div>

            {{-- «Можно продать?» --}}
            @if ($status === 'sold')
                <div class="rounded-xl bg-muted p-3 text-sm">{{ __('Продан') }} @if ($sale)· {{ $sale->number }} · {{ $sale->posted_at?->setTimezone($tz)->format('d.m.Y') }}@endif</div>
            @elseif ($blockers === [])
                <div class="rounded-xl bg-ok/10 p-3 text-sm font-medium text-ok">✓ {{ __('Можно продавать') }}</div>
            @else
                <div class="rounded-xl bg-bad/5 p-3 text-sm text-bad">
                    <div class="font-medium">{{ __('Продать нельзя:') }}</div>
                    <ul class="mt-1 list-inside list-disc">@foreach ($blockers as $b)<li>{{ $b }}</li>@endforeach</ul>
                    @if ($reservation)<div class="mt-1">{{ __('в резерве у :name до :t', ['name' => $reservedBy, 't' => $reservation->expires_at->setTimezone($tz)->format('H:i')]) }}</div>@endif
                </div>
            @endif

            {{-- buttons by status and rights (TZ 04 Э10.3) --}}
            <div class="flex flex-wrap gap-2">
                @if ($status === 'in_stock' && $can['sell'])<a href="{{ \App\Filament\Pos\Pages\Sale::getUrl(['unit' => $unit->id]) }}" class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white"><x-ui::icon name="cart" class="h-4 w-4" /> {{ __('В чек') }}</a>@endif
                @if ($can['price'] && ! in_array($status, ['sold', 'written_off', 'returned_to_supplier']))<button type="button" wire:click="$set('modal', 'price')" class="{{ $btn }}"><x-ui::icon name="pencil" class="h-4 w-4" /> {{ __('Сменить цену') }}</button>@endif
                @if ($can['imei'] && $unit->imei1 && $status !== 'sold')<button type="button" wire:click="$set('modal', 'imei')" class="{{ $btn }}"><x-ui::icon name="shield" class="h-4 w-4" /> {{ __('Проверить госбазу') }}</button>@endif
                @if ($status === 'preparing' && $can['prepare'])<button type="button" wire:click="ready" class="{{ $btn }}">{{ __('Готов к продаже') }}</button>@endif
                @if ($status === 'in_stock' && $unit->condition?->value === 'used' && $can['prepare_cost'])<button type="button" wire:click="toPreparation" class="{{ $btn }}">{{ __('На подготовку') }}</button>@endif
                @if ($status === 'on_check' && $can['prepare_cost'])
                    <button type="button" wire:click="decide('in_stock')" class="{{ $btn }}">{{ __('В продажу') }}</button>
                    <button type="button" wire:click="decide('preparing')" class="{{ $btn }}">{{ __('На подготовку') }}</button>
                    <button type="button" wire:click="decide('defective')" class="{{ $btn }}">{{ __('Брак') }}</button>
                @endif
                @if (in_array($status, ['in_stock', 'preparing', 'defective', 'on_check']) && \App\Support\Ui\Access::allows('stock.write_off'))<a href="{{ \App\Filament\Warehouse\Pages\WriteOffs::getUrl(['unit' => $unit->id]) }}" class="{{ $btn }}">{{ __('Списать') }}</a>@endif
                @if (in_array($status, ['in_stock', 'on_check', 'defective', 'preparing']) && \App\Support\Ui\Access::allows('stock.return_supplier'))<a href="{{ \App\Filament\Warehouse\Pages\WriteOffs::getUrl(['unit' => $unit->id, 'mode' => 'supplier']) }}" class="{{ $btn }}">{{ __('Вернуть поставщику') }}</a>@endif
                @if ($status === 'sold')<a href="{{ \App\Filament\Warehouse\Pages\WarrantyCases::getUrl(['unit' => $unit->id]) }}" class="{{ $btn }}"><x-ui::icon name="shield" class="h-4 w-4" /> {{ __('Гарантийное обращение') }}</a>@endif
                @if (! \App\Support\Ui\Access::instance()->decision('stock.unit_edit')->isDenied())<button type="button" wire:click="$set('modal', 'edit')" class="{{ $btn }}">{{ __('Исправить карточку') }}</button>@endif
                @if (\App\Support\Ui\Access::allows('stock.cost_adjust') && ! in_array($status, ['sold', 'written_off', 'returned_to_supplier']))<button type="button" wire:click="$set('modal', 'cost')" class="{{ $btn }}">{{ __('Корректировать себестоимость') }}</button>@endif
                <button type="button" disabled class="{{ $btn }} opacity-50" title="{{ __('Печать подключается с модулем печати') }}"><x-ui::icon name="printer" class="h-4 w-4" /> {{ __('Этикетка') }}</button>
            </div>
            @if ($error)<p class="text-sm text-bad" role="alert">{{ $error }}</p>@elseif ($flash)<p class="text-sm text-ok" role="status">{{ $flash }}</p>@endif
        </div>
    </div>

    <div class="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1 text-sm" role="tablist">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tabName', '{{ $key }}')" role="tab" class="h-10 shrink-0 px-3 {{ $tabName === $key ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="rounded-xl border border-border bg-card">
        @if ($tabName === 'history')
            <ol class="divide-y divide-border">
                @forelse ($history as $e)
                    <li class="flex gap-4 px-4 py-3 text-sm">
                        <span class="num w-32 shrink-0 text-xs text-muted-foreground">{{ $e['at']?->setTimezone($tz)->format('d.m.Y H:i') }}</span>
                        <span class="flex-1"><span class="font-medium">{{ $e['title'] }}</span> <span class="text-muted-foreground">{{ $e['detail'] }}</span></span>
                        <span class="text-xs text-muted-foreground">{{ $e['who'] }}</span>
                    </li>
                @empty
                    <li class="p-6 text-center text-sm text-muted-foreground">{{ __('Событий пока нет') }}</li>
                @endforelse
            </ol>
        @elseif ($tabName === 'imei')
            <ol class="divide-y divide-border">
                @forelse ($checks as $c)
                    <li class="flex gap-4 px-4 py-3 text-sm"><span class="num w-32 text-xs text-muted-foreground">{{ $c->checked_at->setTimezone($tz)->format('d.m.Y H:i') }}</span><span class="flex-1 {{ $c->result->value === 'registered' ? 'text-ok' : 'text-bad' }}">{{ $c->result->label() }}</span><span class="text-xs text-muted-foreground">{{ $users[$c->checked_by] ?? '' }}</span></li>
                @empty
                    <li class="p-6 text-center text-sm text-muted-foreground">{{ __('Отметок госбазы ещё нет') }}</li>
                @endforelse
            </ol>
        @elseif ($tabName === 'prices')
            <ol class="divide-y divide-border">
                @forelse ($prices as $p)
                    <li class="flex gap-4 px-4 py-3 text-sm"><span class="num w-32 text-xs text-muted-foreground">{{ \Carbon\CarbonImmutable::parse($p->created_at)->setTimezone($tz)->format('d.m.Y H:i') }}</span><span class="num flex-1">{{ $money($p->old_price === null ? null : (int) $p->old_price) }} → {{ $money((int) $p->new_price) }}</span></li>
                @empty
                    <li class="p-6 text-center text-sm text-muted-foreground">{{ __('Цена телефона не менялась (цена модели — в прайсе)') }}</li>
                @endforelse
            </ol>
        @elseif ($tabName === 'used')
            <dl class="grid gap-2 p-4 text-sm sm:grid-cols-2">
                <div><dt class="text-muted-foreground">{{ __('Грейд') }}</dt><dd>{{ $unit->grade ?? '—' }}</dd></div>
                <div><dt class="text-muted-foreground">{{ __('Батарея') }}</dt><dd>{{ $unit->battery_percent ? $unit->battery_percent.'%' : '—' }}</dd></div>
                <div><dt class="text-muted-foreground">{{ __('Год') }}</dt><dd>{{ $unit->year ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-muted-foreground">{{ __('Дефекты') }}</dt><dd>{{ $unit->defects ?: '—' }}</dd></div>
            </dl>
        @elseif ($tabName === 'cost' && $canCost)
            <dl class="grid gap-2 p-4 text-sm sm:grid-cols-3">
                <div><dt class="text-muted-foreground">{{ __('Себестоимость') }}</dt><dd class="num font-semibold">{{ $unit->cost_unknown ? __('не внесена') : $money((int) $unit->cost_kzt) }}</dd></div>
                @if ($unit->cost_currency && $unit->cost_currency !== 'KZT')<div><dt class="text-muted-foreground">{{ __('Закуп') }}</dt><dd class="num">${{ number_format(((int) $unit->cost_amount_orig) / 100, 2, '.', ' ') }} × {{ $unit->cost_rate }}</dd></div>@endif
            </dl>
        @else
            <p class="p-6 text-sm text-muted-foreground">{{ __('Фото и показ на сайте подключаются с модулем сайта.') }}</p>
        @endif
    </div>

    @if ($modal === 'edit' || $modal === 'cost')
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
            <div class="w-full space-y-3 rounded-t-2xl bg-card p-5 desk:w-[460px] desk:rounded-2xl" role="dialog">
                @if ($modal === 'edit')
                    <h3 class="text-lg font-semibold">{{ __('Исправить карточку') }}</h3>
                    <label class="block text-sm">IMEI 1<input type="text" inputmode="numeric" wire:model="edit.imei1" class="num mt-1 w-full rounded-lg border-input bg-background text-base"></label>
                    <label class="block text-sm">IMEI 2<input type="text" inputmode="numeric" wire:model="edit.imei2" class="num mt-1 w-full rounded-lg border-input bg-background text-base"></label>
                @else
                    <h3 class="text-lg font-semibold">{{ __('Корректировка себестоимости') }}</h3>
                    <label class="block text-sm">{{ __('На сколько, ₸ (минус — уменьшить)') }}<input type="text" wire:model="edit.delta" class="num mt-1 w-full rounded-lg border-input bg-background text-base"></label>
                @endif
                <label class="block text-sm">{{ __('Причина') }}<input type="text" wire:model="edit.reason" class="mt-1 w-full rounded-lg border-input bg-background text-base"></label>
                @if ($error)<p class="text-sm text-bad">{{ $error }}</p>@endif
                <div class="flex justify-end gap-2"><button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Отмена') }}</button><button type="button" wire:click="{{ $modal === 'edit' ? 'saveEdit' : 'saveCost' }}" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Провести') }}</button></div>
            </div>
        </div>
    @endif

    @if ($modal === 'price' || $modal === 'imei')
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
            <div class="w-full rounded-t-2xl bg-card p-5 desk:w-[440px] desk:rounded-2xl" role="dialog">
                @if ($modal === 'price')
                    <h3 class="text-lg font-semibold">{{ __('Новая цена') }}</h3>
                    <input type="text" inputmode="numeric" wire:model="newPrice" class="num mt-3 w-full rounded-lg border-input bg-background text-base" placeholder="{{ $price ? number_format($price / 100, 0, '', ' ') : '' }}" autofocus>
                    <div class="mt-4 flex justify-end gap-2"><button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Отмена') }}</button><button type="button" wire:click="price" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Сохранить') }}</button></div>
                @else
                    <h3 class="text-lg font-semibold">{{ __('Проверка госбазы IMEI') }}</h3>
                    <p class="num mt-1 text-sm text-muted-foreground">{{ $unit->imei1 }}</p>
                    <div class="mt-4 grid gap-2">
                        <button type="button" wire:click="imei('registered')" class="h-11 rounded-lg bg-ok/10 font-medium text-ok">{{ __('Зарегистрирован') }}</button>
                        <button type="button" wire:click="imei('not_registered')" class="h-11 rounded-lg bg-bad/10 font-medium text-bad">{{ __('Не зарегистрирован') }}</button>
                        <button type="button" wire:click="imei('blocked')" class="h-11 rounded-lg bg-bad/10 font-medium text-bad">{{ __('Заблокирован') }}</button>
                    </div>
                    <div class="mt-3 flex justify-end"><button type="button" wire:click="$set('modal', null)" class="{{ $btn }}">{{ __('Закрыть') }}</button></div>
                @endif
                @if ($error)<p class="mt-2 text-sm text-bad">{{ $error }}</p>@endif
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
