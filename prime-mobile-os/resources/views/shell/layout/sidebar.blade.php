@php
    use App\Support\Ui\Shell;
    use App\Support\Ui\ShellHook;
    use Filament\Support\Facades\FilamentView;

    $user = Shell::user();
    $role = Shell::role();
    [$plateTitle, $plateLine] = Shell::storePlate();
    $storeStatus = FilamentView::renderHook(ShellHook::SIDEBAR_STORE_STATUS);
    $multiFirm = $user && $user->activeOrganizations()->count() > 1;
    $altIndex = 0;
@endphp
<aside class="pm-sidebar sticky top-0 hidden h-screen shrink-0 flex-col border-r border-border bg-background px-3 desk:flex"
       aria-label="{{ __('Меню') }}">
    <a href="{{ filament()->getHomeUrl() }}" class="flex h-16 shrink-0 items-center px-1.5" aria-label="Prime Mobile">
        <span class="hidden wide:block"><x-ui::logo /></span>
        <span class="wide:hidden"><x-ui::logo :wordmark="false" /></span>
    </a>

    {{-- Store plate (TZ 04 §0.2 п. 1): firm/store and the shift status of the «Смены» module --}}
    <div class="hidden wide:block">
        @if ($multiFirm)
            <a href="{{ route('organization.select') }}" class="flex h-12 items-center gap-2.5 rounded-lg border border-border bg-card px-2.5 text-left hover:bg-muted">
        @else
            <div class="flex h-12 items-center gap-2.5 rounded-lg border border-border bg-card px-2.5 text-left">
        @endif
            <span class="min-w-0 flex-1">
                <span class="block truncate text-[13px] font-medium">{{ $plateTitle }}</span>
                <span class="block truncate text-[11.5px] text-muted-foreground">{{ $storeStatus->isNotEmpty() ? $storeStatus : $plateLine }}</span>
            </span>
            <x-ui::icon name="lock" class="h-3.5 w-3.5 text-muted-foreground" />
        @if ($multiFirm) </a> @else </div> @endif
    </div>

    {{-- «Электроника / Аксессуары» — a filter remembered on the device (TZ 04 §0.2 п. 1) --}}
    <div class="mt-3 hidden wide:block" x-data="pmCatalogSwitch">
        <div class="grid grid-cols-2 rounded-lg bg-muted p-1 text-[12.5px] font-medium" role="tablist">
            <button type="button" role="tab" class="h-8 rounded-md" x-bind:class="value === 'phones' ? 'bg-card shadow-[0_0_0_1px_rgb(var(--border))]' : 'text-muted-foreground'"
                    x-on:click="set('phones')" x-bind:aria-selected="value === 'phones'">{{ __('Электроника') }}</button>
            <button type="button" role="tab" class="h-8 rounded-md" x-bind:class="value === 'accessories' ? 'bg-card shadow-[0_0_0_1px_rgb(var(--border))]' : 'text-muted-foreground'"
                    x-on:click="set('accessories')" x-bind:aria-selected="value === 'accessories'">{{ __('Аксессуары') }}</button>
        </div>
    </div>

    <nav class="-mx-1 mt-1 flex-1 overflow-y-auto px-1 pb-4" data-pm-nav>
        @foreach ($navigation as $group)
            @if ($label = $group->getLabel())
                <div class="hidden px-2.5 pb-1.5 pt-5 text-[11px] font-medium text-muted-foreground/80 wide:block">{{ $label }}</div>
                <div class="mx-auto my-3 h-px w-6 bg-border wide:hidden"></div>
            @endif
            <div class="flex flex-col gap-0.5">
                @foreach ($group->getItems() as $item)
                    @php $altIndex++; @endphp
                    <a href="{{ $item->getUrl() }}" @if ($item->shouldOpenUrlInNewTab()) target="_blank" @endif
                       @class(['pm-sidebar-link', 'is-active' => $item->isActive()])
                       title="{{ $item->getLabel() }}" @if ($altIndex <= 9) data-pm-alt="{{ $altIndex }}" @endif
                       @if ($item->isActive()) aria-current="page" @endif>
                        <x-ui::nav-icon :icon="$item->isActive() ? ($item->getActiveIcon() ?? $item->getIcon()) : $item->getIcon()" />
                        <span class="hidden min-w-0 flex-1 truncate wide:block">{{ $item->getLabel() }}</span>
                        @if (filled($badge = $item->getBadge()))
                            <span class="ml-auto hidden h-5 min-w-[20px] place-items-center rounded-full bg-primary px-1.5 text-[11px] font-medium text-primary-foreground num wide:grid">{{ $badge }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
        {{ FilamentView::renderHook(ShellHook::SIDEBAR_FOOTER) }}
    </nav>

    @if ($user)
        <div class="relative shrink-0 border-t border-border py-3" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <div class="flex items-center gap-2.5 px-1.5">
                <button type="button" class="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg text-left" x-on:click="open = ! open"
                        aria-haspopup="menu" x-bind:aria-expanded="open" title="{{ __('Меню пользователя') }}">
                    <x-ui::avatar :name="$user->name" />
                    <span class="hidden min-w-0 flex-1 leading-tight wide:block">
                        <span class="block truncate text-[13px] font-medium">{{ $user->shortName() }}</span>
                        <span class="block truncate text-[11.5px] text-muted-foreground">{{ $role?->title }}</span>
                    </span>
                </button>
                <form method="post" action="{{ filament()->getLogoutUrl() }}" class="hidden wide:block">
                    @csrf
                    <button type="submit" class="grid h-9 w-9 place-items-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground" title="{{ __('Выйти') }}" aria-label="{{ __('Выйти') }}">
                        <x-ui::icon name="logout" class="h-4 w-4" />
                    </button>
                </form>
            </div>
            <div x-cloak x-show="open" x-on:click.outside="open = false" x-transition.opacity
                 class="absolute bottom-full left-0 z-40 mb-2 w-64 rounded-xl border border-border bg-card p-1.5 shadow-lg" role="menu">
                @include('shell.layout.user-menu')
            </div>
        </div>
    @endif
</aside>
