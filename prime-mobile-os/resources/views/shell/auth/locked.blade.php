<x-shell-auth-layout :title="__('Экран заблокирован')">
    @include('shell.auth.partials.lock-form', ['user' => $user, 'ajax' => false])
</x-shell-auth-layout>
