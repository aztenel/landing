@php $tabs = \App\Support\Ui\Shell::tabs(); @endphp
{{-- iPhone tab bar (TZ 04 §0.3 table): 4 places by role + round «Скан» in the middle; safe area at the bottom. --}}
@if ($tabs)
    <nav class="pm-tabbar fixed inset-x-0 bottom-0 z-30 border-t border-border bg-card desk:hidden" aria-label="{{ __('Разделы') }}">
        <div class="mx-auto flex h-[60px] max-w-[560px] items-start px-2 pt-1.5">
            @foreach ($tabs as $tab)
                @if (isset($tab['scan']))
                    <div class="grid flex-1 place-items-center">
                        <button type="button" class="-mt-6 grid h-14 w-14 place-items-center rounded-2xl bg-primary text-primary-foreground shadow-lg"
                                x-data x-on:click="window.PM && PM.scanner.open()" aria-label="{{ __('Скан') }}" data-pm-scan-button>
                            <x-ui::icon name="scan" class="h-6 w-6" stroke-width="1.8" />
                        </button>
                    </div>
                @elseif (isset($tab['spacer']))
                    <div class="flex-1"></div>
                @else
                    <a href="{{ $tab['url'] }}" @class(['relative flex min-h-[48px] flex-1 flex-col items-center gap-1 pt-0.5', 'text-foreground' => $tab['active'], 'text-muted-foreground' => ! $tab['active']])
                       @if ($tab['active']) aria-current="page" @endif>
                        <x-ui::nav-icon :icon="$tab['icon']" class="h-6 w-6" stroke-width="1.7" />
                        <span class="max-w-full truncate px-0.5 text-[11px] font-medium">{{ $tab['label'] }}</span>
                        @if (filled($tab['badge']))
                            <span class="absolute right-[22%] top-0 grid h-[18px] min-w-[18px] place-items-center rounded-full bg-bad px-1 text-[10.5px] font-semibold text-white">{{ $tab['badge'] }}</span>
                        @endif
                    </a>
                @endif
            @endforeach
        </div>
    </nav>
@endif
