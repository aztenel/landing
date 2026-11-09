@php
    use App\Support\Ui\Shell;
    use App\Support\Ui\ShellHook;
    use Filament\Support\Facades\FilamentView;

    $user = Shell::user();
    $role = Shell::role();
@endphp
<div class="px-2.5 pb-2 pt-1.5">
    <div class="truncate text-[13.5px] font-semibold">{{ $user?->name }}</div>
    <div class="truncate text-[12px] text-muted-foreground">{{ $role?->title }} · {{ $user ? \App\Support\Phone\Phone::format($user->phone) : '' }}</div>
</div>
<div class="my-1 h-px bg-border"></div>
<button type="button" class="flex min-h-[40px] w-full items-center gap-2.5 rounded-lg px-2.5 text-left text-[13.5px] hover:bg-muted" role="menuitem"
        x-on:click="$dispatch('pm-open-device'); open = false">
    <x-ui::icon name="laptop" class="h-4 w-4 text-muted-foreground" />{{ __('Это устройство') }}
    <span class="ml-auto text-[11.5px] text-muted-foreground" x-data x-text="document.documentElement.classList.contains('legacy') ? @js(__('старый ноутбук')) : ''"></span>
</button>
<div class="px-1 py-1"><x-filament-panels::theme-switcher /></div>
<button type="button" class="hidden min-h-[40px] w-full items-center gap-2.5 rounded-lg px-2.5 text-left text-[13.5px] hover:bg-muted desk:flex" role="menuitem"
        x-on:click="window.PM && PM.hotkeys.showHelp(); open = false">
    <x-ui::icon name="keyboard" class="h-4 w-4 text-muted-foreground" />{{ __('Горячие клавиши') }}<x-ui::kbd class="ml-auto">?</x-ui::kbd>
</button>
<button type="button" class="flex min-h-[40px] w-full items-center gap-2.5 rounded-lg px-2.5 text-left text-[13.5px] hover:bg-muted" role="menuitem"
        x-on:click="window.PM && PM.hints.reset(); open = false">
    <x-ui::icon name="info" class="h-4 w-4 text-muted-foreground" />{{ __('Показать подсказки снова') }}
</button>
@if ($user && $user->activeOrganizations()->count() > 1)
    <a href="{{ route('organization.select') }}" class="flex min-h-[40px] items-center gap-2.5 rounded-lg px-2.5 text-[13.5px] hover:bg-muted" role="menuitem">
        <x-ui::icon name="store" class="h-4 w-4 text-muted-foreground" />{{ __('Сменить фирму') }}
    </a>
@endif
{{ FilamentView::renderHook(ShellHook::USER_MENU_ITEMS) }}
<div class="my-1 h-px bg-border"></div>
<form method="post" action="{{ filament()->getLogoutUrl() }}">
    @csrf
    <button type="submit" class="flex min-h-[40px] w-full items-center gap-2.5 rounded-lg px-2.5 text-left text-[13.5px] text-bad hover:bg-muted" role="menuitem">
        <x-ui::icon name="logout" class="h-4 w-4" />{{ __('Выйти') }}
    </button>
</form>
