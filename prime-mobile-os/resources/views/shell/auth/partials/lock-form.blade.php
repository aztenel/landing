{{-- Э1.3: экран блокировки — только пароль этого сотрудника; «Выйти» для другого человека --}}
<div class="w-full max-w-sm rounded-2xl border border-border bg-card p-6 text-center shadow-xl">
    <x-ui::avatar :name="$user->name" class="mx-auto h-14 w-14 text-base" />
    <h2 id="pm-lock-title" class="mt-3 text-lg font-semibold">{{ $user->name }}</h2>
    @php($hasPin = app(\App\Domain\Shell\Services\Pins::class)->has($user, app(\App\Domain\Shell\Services\DeviceRegistry::class)->current(request())))
    <p class="mt-1 text-sm text-muted-foreground">{{ $hasPin ? __('Экран заблокирован. Введите PIN или пароль') : __('Экран заблокирован. Введите свой пароль') }}</p>
    @if (! empty($ajax))
        <form class="mt-4 space-y-3" x-on:submit.prevent="unlock()">
            <input type="password" x-model="password" x-ref="password" autocomplete="current-password" required
                   class="w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary" placeholder="{{ $hasPin ? __('PIN или пароль') : __('Пароль') }}">
            <p class="text-sm text-bad" x-show="error" x-text="error" role="alert"></p>
            <button type="submit" class="h-11 w-full rounded-lg bg-primary text-sm font-semibold text-white" x-bind:disabled="busy">{{ __('Разблокировать') }}</button>
        </form>
    @else
        <form method="post" action="{{ route('erp.shell.unlock') }}" class="mt-4 space-y-3">
            @csrf
            <input type="password" name="password" autocomplete="current-password" required autofocus
                   class="w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary" placeholder="{{ __('Пароль') }}">
            <button type="submit" class="h-11 w-full rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Разблокировать') }}</button>
        </form>
    @endif
    <form method="post" action="{{ \Filament\Facades\Filament::getLogoutUrl() }}" class="mt-3">
        @csrf
        <button type="submit" class="text-sm font-medium text-muted-foreground hover:text-foreground">{{ __('Это не я — выйти') }}</button>
    </form>
</div>
