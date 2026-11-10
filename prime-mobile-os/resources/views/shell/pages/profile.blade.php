<x-filament-panels::page>
@php($btn = 'inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-3 text-sm font-medium hover:bg-muted')
<div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-2">
    <section class="space-y-3 rounded-xl border border-border bg-card p-4">
        <h2 class="font-semibold">{{ $user->name }}</h2>
        <p class="num text-sm text-muted-foreground">{{ $user->phone }}</p>
        <p class="text-sm font-medium">{{ __('Язык интерфейса') }} / Интерфейс тілі</p>
        <div class="flex gap-2">
            <button type="button" wire:click="setLocale('ru')" class="h-10 rounded-lg px-4 text-sm {{ ($user->locale ?? 'ru') === 'ru' ? 'bg-primary font-semibold text-white' : 'border border-border' }}">Русский</button>
            <button type="button" wire:click="setLocale('kk')" class="h-10 rounded-lg px-4 text-sm {{ $user->locale === 'kk' ? 'bg-primary font-semibold text-white' : 'border border-border' }}">Қазақша</button>
        </div>
    </section>
    <section class="space-y-3 rounded-xl border border-border bg-card p-4">
        <h2 class="font-semibold">{{ __('PIN этого устройства') }} {!! $hasPin ? '<span class="text-sm font-normal text-ok">· '.e(__('задан')).'</span>' : '' !!}</h2>
        <p class="text-sm text-muted-foreground">{{ __('Разблокировка экрана после простоя — PIN вместо пароля. У каждого свой PIN на каждом устройстве.') }}</p>
        <div class="grid grid-cols-2 gap-2">
            <input type="password" inputmode="numeric" maxlength="6" wire:model="pin.pin" placeholder="{{ __('PIN, 4–6 цифр') }}" class="rounded-lg border-input bg-background text-base">
            <input type="password" wire:model="pin.password" placeholder="{{ __('Ваш пароль') }}" autocomplete="current-password" class="rounded-lg border-input bg-background text-base">
        </div>
        <button type="button" wire:click="savePin" class="{{ $btn }}">{{ __('Сохранить PIN') }}</button>
    </section>
    <section class="space-y-3 rounded-xl border border-border bg-card p-4">
        <h2 class="font-semibold">{{ __('Вход по коду из приложения') }}</h2>
        @if ($twoFactor)
            <p class="text-sm text-ok">{{ __('Включён. Резервных кодов осталось: :n', ['n' => $codesLeft]) }}</p>
        @else
            <p class="text-sm {{ $twoFactorRequired ? 'text-warn' : 'text-muted-foreground' }}">{{ $twoFactorRequired ? __('Для вашей роли обязателен.') : __('Выключен. Можно включить для надёжности.') }}</p>
            <a href="{{ route('erp.two-factor.setup') }}" class="{{ $btn }}">{{ __('Включить') }}</a>
        @endif
    </section>
    <section class="space-y-3 rounded-xl border border-border bg-card p-4">
        <h2 class="font-semibold">{{ __('Безопасность') }}</h2>
        <p class="text-sm text-muted-foreground">{{ __('Если вы вошли на чужом устройстве и не вышли — закройте все другие входы.') }}</p>
        <button type="button" wire:click="logoutEverywhere" wire:confirm="{{ __('Закрыть все другие входы?') }}" class="{{ $btn }}">{{ __('Выйти везде, кроме этого устройства') }}</button>
    </section>
</div>
@if ($error)<p class="text-sm text-bad" role="alert">{{ $error }}</p>@elseif ($flash)<p class="text-sm text-ok" role="status">{{ $flash }}</p>@endif
</x-filament-panels::page>
