<x-filament-panels::page>
@php
    $money = fn ($t) => \App\Support\Money\Money::kztMinor((int) $t)->format();
    $input = 'mt-1 w-full rounded-lg border-input bg-background text-base';
    $steps = [1 => __('Найти продажу'), 2 => __('Что возвращают'), 3 => __('Деньги'), 4 => __('Итог')];
    $methodLabels = collect(\App\Domain\Money\Enums\PaymentMethodCode::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()]);
@endphp
<div class="mx-auto max-w-4xl space-y-4">
    @if ($step <= 4)
        <ol class="flex flex-wrap gap-2 text-sm">
            @foreach ($steps as $n => $label)
                <li class="flex items-center gap-2 rounded-full px-3 py-1.5 {{ $step === $n ? 'bg-primary text-white' : ($step > $n ? 'bg-ok/10 text-ok' : 'bg-muted text-muted-foreground') }}"><span class="num">{{ $n }}</span> {{ $label }}</li>
            @endforeach
        </ol>
    @endif
    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>@endif

    @if ($step === 1)
        <section class="rounded-xl border border-border bg-card p-4" x-data x-on:pm-scan.window="$wire.set('find', $event.detail.code)">
            <input type="search" wire:model.live.debounce.300ms="find" data-pm-scan-target autofocus placeholder="{{ __('Отсканируйте IMEI, номер чека или введите телефон клиента') }}" class="h-12 w-full rounded-lg border-2 border-primary bg-background text-base">
            <div class="mt-3 divide-y divide-border">
                @foreach ($found as $d)
                    <button type="button" wire:click="chooseSale({{ $d->id }})" class="flex w-full items-center gap-3 py-2.5 text-left text-sm hover:bg-muted" wire:key="f{{ $d->id }}">
                        <span class="num font-medium">{{ $d->number }}</span><span class="text-muted-foreground">{{ $d->posted_at?->setTimezone($tz)->format('d.m.Y H:i') }}</span>
                        <span class="num ml-auto">{{ $money(\App\Domain\Sales\Models\Sale::query()->where('document_id', $d->id)->value('total')) }}</span>
                    </button>
                @endforeach
                @if (mb_strlen(trim($find)) >= 3 && $found->isEmpty())<p class="py-3 text-sm text-muted-foreground">{{ __('Продажа не найдена за 90 дней. Если телефон продан не нами — это Trade-in (F8)') }}</p>@endif
            </div>
            @if ($recent->isNotEmpty())
                <h3 class="mt-5 text-sm font-semibold">{{ __('Последние возвраты') }}</h3>
                @foreach ($recent as $r)<div class="num flex justify-between border-b border-border py-1.5 text-xs"><span>{{ \App\Domain\Ledger\Models\Document::query()->whereKey($r->document_id)->value('number') ?? __('черновик') }}</span><span>{{ $money($r->total) }}</span></div>@endforeach
            @endif
        </section>

    @elseif ($sale && in_array($step, [2, 3, 4], true))
        <section class="rounded-xl border border-border bg-card p-4">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="num font-semibold">{{ $sale->number }}</span>
                <span class="text-muted-foreground">{{ __('Куплено :d дн. назад (:date)', ['d' => $days, 'date' => $sale->posted_at?->setTimezone($tz)->format('d.m.Y')]) }}</span>
                @if ($days !== null && $days > 14)<span class="rounded-full bg-warn/10 px-2 py-0.5 text-xs text-warn">{{ __('Прошло больше 14 дней — возврат только по решению владельца') }}</span>@endif
                <button type="button" wire:click="restart" class="ml-auto text-xs text-muted-foreground">{{ __('Другой чек') }}</button>
            </div>

            @if ($step === 2)
                <div class="mt-3 divide-y divide-border">
                    @foreach ($lines as $l)
                        @php($left = (int) $l->qty - (int) $l->returned_qty)
                        @php($u = $l->stock_unit_id ? \App\Domain\Inventory\Models\StockUnit::query()->find($l->stock_unit_id) : null)
                        <div class="py-3 text-sm {{ $left === 0 ? 'opacity-50' : '' }}" wire:key="l{{ $l->id }}">
                            <label class="flex items-start gap-3">
                                <input type="checkbox" wire:model.live="pick.{{ $l->id }}.on" @disabled($left === 0) class="mt-0.5 h-5 w-5 rounded text-primary">
                                <span class="flex-1"><span class="block font-medium">{{ $u ? $u->variant?->title() : \App\Domain\Catalog\Models\Accessory::query()->find($l->accessory_id)?->name_ru }}</span>
                                    @if ($u)<span class="num block text-xs text-muted-foreground">{{ $u->imei1 }}</span>@endif
                                    @if ($left === 0)<span class="text-xs">{{ __('уже возвращено') }}</span>@endif</span>
                                <span class="num">{{ $money($l->amount) }}</span>
                            </label>
                            @if ($pick[$l->id]['on'] ?? false)
                                <div class="ml-8 mt-2 grid gap-2 sm:grid-cols-3">
                                    @if (! $l->stock_unit_id && $l->qty > 1)<label class="text-xs">{{ __('Кол-во (из :n)', ['n' => $left]) }}<input type="number" min="1" max="{{ $left }}" wire:model.live="pick.{{ $l->id }}.qty" class="{{ $input }} num"></label>@endif
                                    <label class="text-xs">{{ __('Причина') }}<select wire:model="pick.{{ $l->id }}.reason" class="{{ $input }}">
                                        <option value="defect">{{ __('брак') }}</option>
                                        @unless ($l->stock_unit_id)<option value="not_fit">{{ __('не подошёл') }}</option>@endunless
                                        <option value="online">{{ __('онлайн-покупка ≤ 14 дней') }}</option><option value="seller_error">{{ __('ошибка продавца') }}</option><option value="owner">{{ __('по решению владельца') }}</option>
                                    </select></label>
                                    @if ($l->stock_unit_id)
                                        <label class="text-xs">{{ __('Состояние') }}<select wire:model.live="pick.{{ $l->id }}.status" class="{{ $input }}"><option value="in_stock">{{ __('Целый, в продажу') }}</option><option value="on_check">{{ __('На проверку') }}</option><option value="defective">{{ __('Брак') }}</option></select></label>
                                        <label class="flex items-center gap-2 text-xs sm:mt-5"><input type="checkbox" wire:model="pick.{{ $l->id }}.icloud" class="h-4 w-4 rounded text-primary"> {{ __('iCloud / FRP отвязан, комплект на месте') }}</label>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="exchange" class="h-5 w-5 rounded text-primary"> {{ __('Обмен: клиент берёт другой товар') }}</label>
                @if ($exchange)
                    <div class="mt-3 rounded-lg border border-border p-3">
                        <h4 class="text-sm font-semibold">{{ __('Берут') }}</h4>
                        <div class="mt-2 flex gap-2"><input type="text" wire:model="takeCode" wire:keydown.enter="addTake" placeholder="{{ __('IMEI, PM-код или штрихкод') }}" class="min-w-0 flex-1 rounded-lg border-input bg-background text-base"><button type="button" wire:click="addTake" class="h-10 rounded-lg border border-border px-3 text-sm">+</button></div>
                        @foreach ($takeRows as $t)<div class="mt-2 flex items-center gap-2 text-sm"><span class="flex-1">{{ $t['title'] }}</span><span class="num">{{ $money($t['price']) }}</span><button type="button" wire:click="dropTake({{ $t['i'] }})" class="text-muted-foreground">×</button></div>@endforeach
                    </div>
                @endif
                <div class="mt-4 flex justify-end"><button type="button" wire:click="toMoney" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ __('Дальше') }}</button></div>

            @elseif ($step === 3 || $step === 4)
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between"><dt>{{ __('Сумма возврата') }}</dt><dd class="num font-semibold">{{ $money($q['total']) }}</dd></div>
                    @if ($q['restore'])<div class="flex justify-between text-muted-foreground"><dt>{{ __('Бонусы вернутся на счёт клиента') }}</dt><dd class="num">{{ number_format($q['restore'], 0, '', ' ') }}</dd></div>@endif
                    @if ($q['cancel'])<div class="flex justify-between text-muted-foreground"><dt>{{ __('Бонусы за покупку будут аннулированы') }}</dt><dd class="num">{{ number_format($q['cancel'], 0, '', ' ') }}</dd></div>@endif
                    @if ($q['withheld'])<div class="flex justify-between text-warn"><dt>{{ __('Удержание за потраченные бонусы') }}</dt><dd class="num">−{{ $money($q['withheld']) }}</dd></div>@endif
                    <div class="flex justify-between text-base font-semibold"><dt>{{ __('Вернуть клиенту') }}</dt><dd class="num">{{ $money($q['refund']) }}</dd></div>
                    @if ($exchange)<div class="flex justify-between"><dt>{{ __('Новый товар') }}</dt><dd class="num">{{ $money($q['take']) }}</dd></div>
                        <div class="flex justify-between font-semibold {{ $q['take'] > $q['refund'] ? 'text-warn' : '' }}"><dt>{{ $q['take'] > $q['refund'] ? __('Клиент доплачивает') : __('Вернуть разницу') }}</dt><dd class="num">{{ $money(abs($q['take'] - $q['refund'])) }}</dd></div>@endif
                </dl>
                <h4 class="mt-4 text-sm font-semibold">{{ __('Как вернуть') }}</h4>
                @foreach ($refunds as $i => $r)
                    <div class="mt-2 flex gap-2" wire:key="rf{{ $i }}">
                        @if (in_array($r['method'], ['debt', 'exchange_offset'], true))
                            <span class="flex h-10 flex-1 items-center rounded-lg bg-muted px-3 text-sm">{{ $r['method'] === 'debt' ? __('Уменьшить долг клиента') : __('В зачёт нового товара') }}</span>
                        @else
                            <select wire:model.live="refunds.{{ $i }}.method" class="min-w-0 flex-1 rounded-lg border-input bg-background text-sm">@foreach (['cash', 'card', 'kaspi_qr', 'kaspi_transfer'] as $m)<option value="{{ $m }}">{{ $methodLabels[$m] }}</option>@endforeach</select>
                        @endif
                        <input type="text" inputmode="numeric" wire:model.live.debounce.400ms="refunds.{{ $i }}.amount" @disabled($r['method'] === 'debt') class="num w-36 rounded-lg border-input bg-background text-right text-base">
                    </div>
                @endforeach
                @if ($exchange && $q['take'] > $q['refund'])
                    <label class="mt-3 block text-sm">{{ __('Доплата клиента') }}<select wire:model.live="extraMethod" class="{{ $input }}">@foreach (['cash', 'card', 'kaspi_qr', 'kaspi_transfer'] as $m)<option value="{{ $m }}">{{ $methodLabels[$m] }}</option>@endforeach</select></label>
                @endif
                <label class="mt-3 block text-sm">{{ __('Комментарий') }}<input type="text" wire:model="comment" class="{{ $input }}"></label>

                <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                    <button type="button" wire:click="$set('step', 2)" class="h-11 rounded-lg border border-border px-4 text-sm">{{ __('Назад') }}</button>
                    @if ($request && $request->status->value === 'pending')
                        <span wire:poll.3s="pollRequest" class="text-sm text-muted-foreground">{{ __('Заявка отправлена. Ждём ответа владельца…') }}</span>
                    @elseif ($request && $request->status->value === 'rejected')
                        <span class="text-sm text-bad">{{ __('Отклонено') }}{{ $request->decision_comment ? ': «'.$request->decision_comment.'»' : '' }}</span>
                    @elseif ($canPost && $step === 3)
                        <button type="button" wire:click="post" wire:loading.attr="disabled" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ $exchange ? __('Провести обмен') : __('Провести возврат') }}</button>
                    @else
                        <button type="button" wire:click="sendRequest" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ __('Отправить заявку') }}</button>
                    @endif
                </div>
            @endif
        </section>

    @elseif ($step === 5 && $done)
        <section class="rounded-2xl border border-border bg-card p-6 text-center">
            <x-ui::icon name="check" class="mx-auto h-12 w-12 text-ok" />
            <h2 class="mt-2 text-xl font-semibold">{{ __('Возврат :n проведён', ['n' => $done->number]) }}</h2>
            <p class="mt-1 text-sm text-muted-foreground">{{ __('Распечатайте акт возврата и дайте клиенту подписать (бланк — с модулем печати).') }}</p>
            <button type="button" wire:click="restart" class="mt-4 h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Новый возврат') }}</button>
        </section>
    @endif
</div>
</x-filament-panels::page>
