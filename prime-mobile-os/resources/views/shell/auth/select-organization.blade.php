<x-shell-auth-layout :title="__('Выбор фирмы')">
    <h1 class="text-lg font-semibold">{{ __('В какой фирме работаем?') }}</h1>
    <div class="mt-4 space-y-2">
        @foreach ($organizations as $org)
            <form method="post" action="{{ route('organization.switch', $org) }}">
                @csrf
                <button type="submit" class="flex min-h-[48px] w-full items-center justify-between rounded-lg border border-border px-4 text-left text-sm font-medium hover:border-primary">
                    {{ $org->name }} <x-ui::icon name="store" class="h-4 w-4 text-muted-foreground" />
                </button>
            </form>
        @endforeach
    </div>
</x-shell-auth-layout>
