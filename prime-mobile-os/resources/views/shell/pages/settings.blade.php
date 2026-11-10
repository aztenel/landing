<x-filament-panels::page>
    @php($input = 'mt-1 w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary')
    @php($num = 'num mt-1 w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary')

    @if ($isOwner)
        <form wire:submit="saveFirm" class="space-y-4">
            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="font-semibold">{{ __('Фирма и реквизиты для чеков') }}</h2>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <label class="text-sm font-medium">{{ __('Название') }}<input type="text" wire:model="firm.name" class="{{ $input }}"></label>
                    <label class="text-sm font-medium">{{ __('Юридическое название (ИП / ТОО)') }}<input type="text" wire:model="firm.legal_name" class="{{ $input }}"></label>
                    <label class="text-sm font-medium">{{ __('БИН / ИИН') }}<input type="text" inputmode="numeric" wire:model="firm.bin_iin" class="{{ $num }}"></label>
                    <label class="text-sm font-medium">{{ __('Телефон на чеке') }}<input type="text" wire:model="firm.phone" class="{{ $input }}"></label>
                    <label class="text-sm font-medium md:col-span-2">{{ __('Адрес на чеке') }}<input type="text" wire:model="firm.address" class="{{ $input }}"></label>
                    <label class="text-sm font-medium">{{ __('Instagram') }}<input type="text" wire:model="firm.instagram" class="{{ $input }}"></label>
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="font-semibold">{{ __('Вид') }}</h2>
                <div class="mt-3 flex gap-3">
                    <label class="flex min-h-[44px] cursor-pointer items-center gap-2 rounded-lg border border-border px-3"><input type="radio" value="blue" wire:model="firm.accent" class="text-primary"> <span class="h-4 w-4 rounded-full" style="background:#2A4BD7"></span> {{ __('Синий') }}</label>
                    <label class="flex min-h-[44px] cursor-pointer items-center gap-2 rounded-lg border border-border px-3"><input type="radio" value="graphite" wire:model="firm.accent" class="text-primary"> <span class="h-4 w-4 rounded-full" style="background:#18181B"></span> {{ __('Графит') }}</label>
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="font-semibold">{{ __('Бонусы') }}</h2>
                <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="firm.bonus_enabled" class="h-5 w-5 rounded border-input text-primary"> {{ __('Начислять бонусы клиентам') }}</label>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <label class="text-sm font-medium">{{ __('Телефоны, %') }}<input type="text" inputmode="decimal" wire:model="firm.bonus_phone" class="{{ $num }}"></label>
                    <label class="text-sm font-medium">{{ __('Аксессуары и услуги, %') }}<input type="text" inputmode="decimal" wire:model="firm.bonus_accessory" class="{{ $num }}"></label>
                    <label class="text-sm font-medium">{{ __('Оплата бонусами, не больше % чека') }}<input type="text" inputmode="decimal" wire:model="firm.bonus_max_pay" class="{{ $num }}"></label>
                    <label class="text-sm font-medium">{{ __('Активация через, дней') }}<input type="number" wire:model="firm.bonus_activation_days" class="{{ $num }}"></label>
                    <label class="text-sm font-medium">{{ __('Сгорают через, дней') }}<input type="number" wire:model="firm.bonus_lifetime_days" class="{{ $num }}"></label>
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="font-semibold">{{ __('Продажи и касса') }}</h2>
                <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="firm.require_imei_check" class="h-5 w-5 rounded border-input text-primary"> {{ __('Без свежей отметки госбазы не продаём') }}</label>
                <p class="mt-1 text-xs text-muted-foreground">{{ __('«Не верифицирован» и «Заблокирован» не продаются никогда, даже если выключить.') }}</p>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <label class="text-sm font-medium">{{ __('Отметка госбазы действует, дней') }}<input type="number" wire:model="firm.imei_max_age_days" class="{{ $num }}"></label>
                    <label class="text-sm font-medium">{{ __('Корзина держит телефон, минут') }}<input type="number" wire:model="firm.reservation_minutes" class="{{ $num }}"></label>
                    <label class="text-sm font-medium">{{ __('Бронь заказа, дней') }}<input type="number" wire:model="firm.order_hold_days" class="{{ $num }}"></label>
                    <label class="text-sm font-medium">{{ __('Потолок долга одного клиента, ₸') }}<input type="text" inputmode="numeric" wire:model="firm.debt_ceiling" class="{{ $num }}"></label>
                    <label class="text-sm font-medium">{{ __('Расхождение кассы — задача мне, от ₸') }}<input type="text" inputmode="numeric" wire:model="firm.cash_threshold" class="{{ $num }}"></label>
                    <label class="text-sm font-medium">{{ __('Задним числом, не больше дней') }}<input type="number" wire:model="firm.backdate_days" class="{{ $num }}"></label>
                </div>
            </section>

            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="font-semibold">{{ __('Сайт') }}</h2>
                <label class="mt-3 block max-w-xs text-sm font-medium">{{ __('Приставка кода телефона (PM-10001)') }}<input type="text" wire:model="firm.code_prefix" maxlength="4" class="{{ $num }} uppercase"></label>
                <p class="mt-1 text-xs text-muted-foreground">{{ __('Меняется только до первого телефона: потом коды уже на ценниках.') }}</p>
            </section>

            @if ($errors->any())<p class="text-sm text-bad" role="alert">{{ $errors->first() }}</p>@endif
            <div class="flex justify-end"><button type="submit" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white">{{ __('Сохранить настройки') }}</button></div>
        </form>
    @endif

    <form wire:submit="saveStores" class="rounded-xl border border-border bg-card p-5">
        <h2 class="font-semibold">{{ __('Магазины') }}</h2>
        @foreach ($stores as $id => $store)
            <div class="mt-3 grid gap-3 md:grid-cols-3" wire:key="s{{ $id }}">
                <label class="text-sm font-medium">{{ __('Название') }}<input type="text" wire:model="stores.{{ $id }}.name" class="{{ $input }}"></label>
                <label class="text-sm font-medium">{{ __('Адрес') }}<input type="text" wire:model="stores.{{ $id }}.address" class="{{ $input }}"></label>
                <label class="text-sm font-medium">{{ __('Телефон') }}<input type="text" wire:model="stores.{{ $id }}.phone" class="{{ $input }}"></label>
            </div>
        @endforeach
        <div class="mt-4 flex justify-end"><button type="submit" class="h-11 rounded-lg border border-border px-6 text-sm font-semibold">{{ __('Сохранить магазины') }}</button></div>
    </form>
</x-filament-panels::page>
