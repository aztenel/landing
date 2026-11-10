<x-shell-auth-layout :title="__('Первый вход')">
    <h1 class="text-lg font-semibold">{{ __('Здравствуйте, :name!', ['name' => explode(' ', trim($user->name))[0]]) }}</h1>
    <p class="mt-1 text-sm text-muted-foreground">{{ __('Придумайте свой пароль. Выданный пароль больше не подойдёт.') }}</p>
    <form method="post" action="{{ route('erp.first-login.store') }}" class="mt-5 space-y-4">
        @csrf
        <div>
            <label for="password" class="block text-sm font-medium">{{ __('Новый пароль') }}</label>
            <input id="password" type="password" name="password" required autocomplete="new-password" class="mt-1 w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary">
            <p class="mt-1 text-xs text-muted-foreground">{{ __('Не короче 8 символов: буквы и цифры') }}</p>
        </div>
        <div>
            <label for="password_confirmation" class="block text-sm font-medium">{{ __('Повторите пароль') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary">
        </div>
        @if (! empty($rules))
            <details class="rounded-lg border border-border p-3 text-sm"><summary class="cursor-pointer font-medium">{{ __('Правила работы с данными клиентов') }}</summary><div class="prose prose-sm mt-2 max-w-none">{!! nl2br(e($rules)) !!}</div></details>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="rules" value="1" required class="mt-0.5 h-5 w-5 rounded border-input text-primary"> {{ __('Прочитал(а) и согласен(на) с правилами') }}</label>
        @endif
        @if ($errors->any())<p class="text-sm text-bad" role="alert">{{ $errors->first() }}</p>@endif
        <button type="submit" class="h-11 w-full rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Сохранить и войти') }}</button>
    </form>
</x-shell-auth-layout>
