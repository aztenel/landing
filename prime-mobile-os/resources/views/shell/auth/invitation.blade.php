<x-shell-auth-layout :title="$isReset ? __('Новый пароль') : __('Приглашение')">
    @if ($isReset)
        <h1 class="text-lg font-semibold">{{ __('Новый пароль для :name', ['name' => $person->name]) }}</h1>
    @else
        <h1 class="text-lg font-semibold">{{ __(':inviter приглашает вас в «:org»', ['inviter' => $inviter?->name, 'org' => $organization->name]) }}</h1>
        <p class="mt-1 text-sm text-muted-foreground">{{ __('Роль: :role. Вход по номеру :phone.', ['role' => $role, 'phone' => \App\Support\Phone\Phone::format($person->phone)]) }}</p>
    @endif
    <form method="post" action="{{ route('erp.invitation.store', ['token' => request()->route('token')]) }}" class="mt-5 space-y-4">
        @csrf
        <div>
            <label for="password" class="block text-sm font-medium">{{ __('Придумайте пароль') }}</label>
            <input id="password" type="password" name="password" required autocomplete="new-password" class="mt-1 w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary">
        </div>
        <div>
            <label for="password_confirmation" class="block text-sm font-medium">{{ __('Повторите пароль') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary">
        </div>
        @if (! $isReset && ! empty($rules))
            <details class="rounded-lg border border-border p-3 text-sm"><summary class="cursor-pointer font-medium">{{ __('Правила работы с данными клиентов') }}</summary><div class="mt-2">{!! nl2br(e($rules)) !!}</div></details>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="rules" value="1" required class="mt-0.5 h-5 w-5 rounded border-input text-primary"> {{ __('Прочитал(а) и согласен(на) с правилами') }}</label>
        @endif
        @if ($errors->any())<p class="text-sm text-bad" role="alert">{{ $errors->first() }}</p>@endif
        <button type="submit" class="h-11 w-full rounded-lg bg-primary text-sm font-semibold text-white">{{ $isReset ? __('Сохранить пароль') : __('Принять и войти') }}</button>
    </form>
</x-shell-auth-layout>
