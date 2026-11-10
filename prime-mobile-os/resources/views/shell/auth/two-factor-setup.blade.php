<x-shell-auth-layout :title="__('Вход по коду')">
    @if ($codes)
        <h1 class="text-lg font-semibold">{{ __('Готово. Сохраните резервные коды') }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">{{ __('Если потеряете телефон — войдёте по одному из этих кодов (каждый — один раз). Распечатайте и храните дома. Больше они не покажутся.') }}</p>
        <ol class="num mt-4 grid grid-cols-2 gap-2 rounded-lg border border-border p-3 text-center text-base">@foreach ($codes as $c)<li>{{ $c }}</li>@endforeach</ol>
        <div class="mt-4 flex gap-2"><button type="button" onclick="window.print()" class="h-11 flex-1 rounded-lg border border-border text-sm font-medium">{{ __('Печать') }}</button>
            <a href="{{ url('/') }}" class="grid h-11 flex-1 place-items-center rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Продолжить') }}</a></div>
    @else
        <h1 class="text-lg font-semibold">{{ __('Включите вход по коду') }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">{{ __('Для вашей роли нужен второй шаг входа: пароль + 6 цифр из приложения на телефоне.') }}</p>
        <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm">
            <li>{{ __('Установите Google Authenticator или откройте «Пароли» на iPhone.') }}</li>
            <li>{{ __('Отсканируйте QR-код камерой этого приложения.') }}</li>
            <li>{{ __('Введите 6 цифр, которые оно покажет.') }}</li>
        </ol>
        <div class="mx-auto mt-4 w-44 rounded-lg bg-white p-2 [&_svg]:h-auto [&_svg]:w-full">{!! $qr !!}</div>
        <p class="num mt-2 break-all text-center text-xs text-muted-foreground">{{ __('Ключ вручную') }}: {{ trim(chunk_split($secret, 4, ' ')) }}</p>
        <form method="post" action="{{ route('erp.two-factor.confirm') }}" class="mt-4 space-y-3">
            @csrf
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="7" placeholder="123456"
                   class="num w-full rounded-lg border-input bg-background text-center text-2xl tracking-[.3em] focus:border-primary focus:ring-primary">
            @if ($errors->any())<p class="text-sm text-bad" role="alert">{{ $errors->first() }}</p>@endif
            <button type="submit" class="h-11 w-full rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Включить') }}</button>
        </form>
    @endif
</x-shell-auth-layout>
