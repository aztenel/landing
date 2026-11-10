<x-filament-panels::page>
@php
    $input = 'mt-1 w-full rounded-lg border-input bg-background text-base';
    $money = fn ($t) => \App\Support\Money\Money::kztMinor((int) $t)->format();
    $step = fn ($n, $title) => '<h2 class="flex items-center gap-2 font-semibold"><span class="num flex h-7 w-7 items-center justify-center rounded-full bg-primary text-sm text-white">'.$n.'</span>'.e($title).'</h2>';
@endphp
@if ($posted)
    <div class="mx-auto max-w-xl rounded-2xl border border-border bg-card p-6 text-center">
        <x-ui::icon name="check" class="mx-auto h-12 w-12 text-ok" />
        <h2 class="mt-2 text-xl font-semibold">{{ __('Акт :n проведён', ['n' => $posted->number]) }}</h2>
        <p class="mt-1 text-sm text-muted-foreground">{{ __('Распечатайте акт и дайте клиенту подписать.') }}</p>
        <div class="mt-4 flex justify-center gap-2">
            <a href="{{ route('erp.tradein.print', $posted) }}" target="_blank" class="inline-flex h-10 items-center gap-2 rounded-lg border border-border px-4 text-sm"><x-ui::icon name="printer" class="h-4 w-4" /> {{ __('Печать акта') }}</a>
            <a href="{{ \App\Filament\TradeIn\Pages\TradeIns::getUrl() }}" class="inline-flex h-10 items-center rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('К списку актов') }}</a>
        </div>
    </div>
@else
<div class="grid grid-cols-[minmax(0,1fr)] gap-4 pb-40 desk:grid-cols-[minmax(0,1fr)_340px] desk:pb-0" x-data x-on:pm-scan.window="$wire.set('imei1', $event.detail.code)">
    <div class="space-y-4">
        <section class="space-y-3 rounded-xl border border-border bg-card p-4">
            {!! $step(1, __('Устройство и IMEI')) !!}
            <label class="block text-sm font-medium">{{ __('Модель') }}<input type="text" wire:model.live.debounce.300ms="variantQuery" x-on:input="$wire.set('variantId', null)" placeholder="{{ __('например 13 128') }}" class="{{ $input }}"></label>
            @foreach ($variants as $v)<button type="button" wire:click="pickVariant({{ $v->id }})" class="block w-full px-3 py-1.5 text-left text-sm hover:bg-muted">{{ $v->title() }}</button>@endforeach
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm font-medium">IMEI 1<input type="text" inputmode="numeric" wire:model.live.debounce.400ms="imei1" data-pm-scan-target class="{{ $input }} num"></label>
                <label class="block text-sm font-medium">IMEI 2<input type="text" inputmode="numeric" wire:model="imei2" class="{{ $input }} num"></label>
            </div>
            @if ($known)<p class="rounded-lg bg-primary/5 px-3 py-2 text-sm">{{ $known['sold'] ? __('Этот телефон мы продавали :d (клиент :c). История продолжится.', ['d' => $known['sold_at']?->format('d.m.Y'), 'c' => $known['customer'] ?? '—']) : __('Этот IMEI уже у нас на складе: :code', ['code' => $known['unit']->public_code]) }}</p>@endif
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="imeiVerified" class="h-5 w-5 rounded text-primary"> {{ __('IMEI сверен с *#06# на телефоне клиента') }}</label>
            <label class="block text-sm font-medium">{{ __('Госбаза IMEI') }}<select wire:model.live="imeiCheck" class="{{ $input }}"><option value="">{{ __('Проверьте на сайте госбазы') }}</option><option value="registered">{{ __('Зарегистрирован') }}</option><option value="not_registered">{{ __('Не зарегистрирован') }}</option><option value="blocked">{{ __('Заблокирован') }}</option></select></label>
            @if (in_array($imeiCheck, ['not_registered', 'blocked'], true))<p class="rounded-lg bg-bad/10 px-3 py-2 text-sm font-medium text-bad">{{ __('Принимать нельзя: телефон не прошёл госбазу') }}</p>@endif
        </section>

        <section class="space-y-2 rounded-xl border border-border bg-card p-4">
            {!! $step(2, __('Чек-лист')) !!}
            @if (($checklist['icloud'] ?? null) === 'no')<p class="rounded-lg bg-bad/10 px-3 py-2 text-sm font-medium text-bad">{{ __('С включённым iCloud телефон не принимаем') }}</p>@endif
            @foreach (\App\Filament\TradeIn\Pages\NewAct::CHECKS as $key => $label)
                <div class="flex flex-wrap items-center gap-2 border-b border-border py-1.5 text-sm last:border-0" wire:key="c{{ $key }}">
                    <span class="min-w-[200px] flex-1">{{ $label }}</span>
                    @foreach (['yes' => __('Да'), 'no' => __('Нет'), 'skip' => __('Не проверял')] as $v => $l)
                        <button type="button" wire:click="$set('checklist.{{ $key }}', '{{ $v }}')" @class(['h-9 rounded-lg border px-3 text-xs', 'border-primary bg-primary text-white' => ($checklist[$key] ?? null) === $v, 'border-border' => ($checklist[$key] ?? null) !== $v])>{{ $l }}</button>
                    @endforeach
                </div>
            @endforeach
            <div class="grid gap-3 pt-2 sm:grid-cols-3">
                <label class="block text-sm font-medium">{{ __('Батарея, %') }}<input type="number" min="1" max="100" wire:model.live.debounce.400ms="battery" class="{{ $input }} num"></label>
                <fieldset class="text-sm"><legend class="font-medium">{{ __('Заменены') }}</legend>
                    @foreach (['screen' => __('экран'), 'battery' => __('батарея'), 'camera' => __('камера'), 'body' => __('корпус')] as $k => $l)<label class="mr-3 inline-flex items-center gap-1"><input type="checkbox" value="{{ $k }}" wire:model.live="replaced" class="h-4 w-4 rounded"> {{ $l }}</label>@endforeach</fieldset>
                <fieldset class="text-sm"><legend class="font-medium">{{ __('Комплект') }}</legend>
                    @foreach (['box' => __('коробка'), 'cable' => __('кабель')] as $k => $l)<label class="mr-3 inline-flex items-center gap-1"><input type="checkbox" value="{{ $k }}" wire:model.live="kit" class="h-4 w-4 rounded"> {{ $l }}</label>@endforeach</fieldset>
            </div>
        </section>

        <section class="space-y-3 rounded-xl border border-border bg-card p-4">
            {!! $step(3, __('Грейд')) !!}
            <div class="grid grid-cols-3 gap-2">
                @foreach (['A' => __('Отличное'), 'B' => __('Хорошее'), 'C' => __('Заметные следы')] as $g => $l)
                    <button type="button" wire:click="$set('grade', '{{ $g }}')" @class(['rounded-xl border p-3 text-left', 'border-primary ring-1 ring-primary' => $grade === $g, 'border-border' => $grade !== $g])><span class="block text-xl font-bold">{{ $g }}</span><span class="text-xs text-muted-foreground">{{ $l }}</span></button>
                @endforeach
            </div>
            <p class="text-xs text-muted-foreground">{{ __('Фото для сайта добавите в паспорте телефона после приёма.') }}</p>
        </section>

        <section class="space-y-3 rounded-xl border border-border bg-card p-4">
            {!! $step(4, __('Оценка')) !!}
            <label class="block text-sm font-medium">{{ __('Сумма, ₸') }}<input type="text" inputmode="numeric" wire:model.live.debounce.400ms="amount" class="{{ $input }} num text-lg"></label>
            @if ($quote)
                @php($sum = (int) preg_replace('/\D/', '', $amount) * 100)
                @if ($sum > $quote['price'])<p class="text-sm text-warn">{{ __('Сумма выше сетки на :d — нужна заявка (если выше вашего допуска)', ['d' => $money($sum - $quote['price'])]) }}</p>@endif
            @elseif ($variantId)
                <p class="text-sm text-warn">{{ __('Для этой модели нет сетки — выкуп только с разрешения владельца') }}</p>
            @endif
        </section>

        <section class="space-y-3 rounded-xl border border-border bg-card p-4">
            {!! $step(5, __('Кто сдаёт и как платим')) !!}
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm font-medium">{{ __('Телефон') }}<input type="tel" wire:model="person.phone" placeholder="+7 701 000 00 00" class="{{ $input }} num"></label>
                <label class="block text-sm font-medium">{{ __('ФИО') }}<input type="text" wire:model="person.name" class="{{ $input }}"></label>
                <label class="block text-sm font-medium">{{ __('ИИН') }}<input type="text" inputmode="numeric" maxlength="12" wire:model.live.debounce.400ms="person.iin" class="{{ $input }} num"></label>
                <label class="block text-sm font-medium">{{ __('№ удостоверения') }}<input type="text" inputmode="numeric" wire:model="person.doc" class="{{ $input }} num"></label>
            </div>
            @unless ($iinOk)<p class="text-sm text-bad">{{ __('ИИН с ошибкой: проверьте цифры') }}</p>@endunless
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="person.consent" class="h-5 w-5 rounded text-primary"> {{ __('Согласие на обработку персональных данных получено') }}</label>
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach (array_filter(['offset' => $tab ? __('В зачёт покупки') : null, 'cash' => __('Выкуп наличными'), 'kaspi' => __('Выкуп на Kaspi'), 'split' => __('Наличные + Kaspi')]) as $m => $l)
                    <button type="button" wire:click="$set('mode', '{{ $m }}')" @class(['h-10 rounded-lg border px-3', 'border-primary bg-primary text-white' => $mode === $m, 'border-border' => $mode !== $m])>{{ $l }}</button>
                @endforeach
            </div>
            @if ($mode === 'split')<label class="block text-sm font-medium">{{ __('Наличными, ₸ (остальное — Kaspi)') }}<input type="text" inputmode="numeric" wire:model="cashPart" class="{{ $input }} num"></label>@endif
            @if (in_array($mode, ['kaspi', 'split'], true))<label class="block text-sm font-medium">{{ __('Номер операции Kaspi') }}<input type="text" wire:model="kaspiRef" class="{{ $input }} num"></label>@endif
            @if ($mode === 'offset')<p class="text-xs text-muted-foreground">{{ __('В зачёт уйдёт не больше суммы чека; остаток — наличными из кассы.') }}</p>@endif
        </section>
    </div>

    <aside class="fixed inset-x-0 bottom-16 z-20 space-y-2 border-t border-border bg-card p-4 desk:sticky desk:inset-x-auto desk:bottom-auto desk:top-4 desk:self-start desk:rounded-xl desk:border">
        <div class="text-sm font-medium">{{ $variantQuery ?: __('Модель не выбрана') }} · {{ $grade }}{{ $battery !== '' ? ' · '.$battery.'%' : '' }}</div>
        @if ($quote)
            <div class="text-xs text-muted-foreground">{{ __('Сетка') }}: {{ $money($quote['base']) }}@foreach ($quote['deductions'] as $d) − {{ $money($d['amount']) }} ({{ $d['label'] }})@endforeach = <span class="num font-semibold text-foreground">{{ $money($quote['price']) }}</span></div>
        @endif
        <div class="num text-2xl font-bold">{{ $amount !== '' ? $money((int) preg_replace('/\D/', '', $amount) * 100) : '—' }}</div>
        @if ($request && $request->status->value === 'pending')
            <p wire:poll.3s="poll" class="text-sm text-muted-foreground">{{ __('Заявка владельцу отправлена. Ждём ответа…') }}</p>
        @elseif ($request && $request->status->value === 'rejected')
            <p class="text-sm text-bad">{{ __('Отклонено') }}{{ $request->decision_comment ? ': «'.$request->decision_comment.'»' : '' }}</p>
        @else
            <button type="button" wire:click="submit" wire:loading.attr="disabled" @disabled($blocker !== null) class="h-12 w-full rounded-xl bg-primary text-sm font-semibold text-white disabled:opacity-40">{{ $mode === 'offset' ? __('Добавить в чек') : __('Провести') }}</button>
            @if ($blocker)<p class="text-center text-xs text-muted-foreground">{{ $blocker }}</p>@endif
            @if ($error && ! $request)
                <p class="text-sm text-bad" role="alert">{{ $error }}</p>
                @if (str_contains($error, 'заявк'))<button type="button" wire:click="sendRequest" class="h-10 w-full rounded-lg border border-primary text-sm font-medium text-primary">{{ __('Запросить у владельца') }}</button>@endif
            @endif
        @endif
    </aside>
</div>
@endif
</x-filament-panels::page>
