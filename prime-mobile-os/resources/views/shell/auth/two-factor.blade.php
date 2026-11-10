<x-shell-auth-layout :title="__('Код из приложения')">
    <h1 class="text-lg font-semibold">{{ __('Код из приложения') }}</h1>
    <p class="mt-1 text-sm text-muted-foreground">{{ __('Откройте Google Authenticator или «Пароли» на iPhone и введите 6 цифр для Prime Mobile.') }}</p>
    <form method="post" action="{{ route('erp.two-factor.verify') }}" class="mt-5 space-y-4">
        @csrf
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required maxlength="20" placeholder="123 456"
               class="num w-full rounded-lg border-input bg-background text-center text-2xl tracking-[.3em] focus:border-primary focus:ring-primary">
        @if ($canRemember)
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1" class="h-5 w-5 rounded border-input text-primary"> {{ __('Запомнить это устройство на 30 дней') }}</label>
        @endif
        @if ($errors->any())<p class="text-sm text-bad" role="alert">{{ $errors->first() }}</p>@endif
        <button type="submit" class="h-11 w-full rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Войти') }}</button>
        <p class="text-xs text-muted-foreground">{{ __('Нет телефона? Введите резервный код вида ABCD-1234 из распечатки.') }}</p>
    </form>
    <form method="post" action="{{ filament()->getLogoutUrl() }}" class="mt-3">@csrf<button class="text-sm text-muted-foreground underline">{{ __('Выйти') }}</button></form>
</x-shell-auth-layout>
