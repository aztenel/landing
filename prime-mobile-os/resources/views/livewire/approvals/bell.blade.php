<div class="relative" wire:poll.10s>
    <button type="button" wire:click="$toggle('open')" class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted" aria-label="{{ __('Заявки и уведомления') }}">
        <x-ui::icon name="bell" class="h-[18px] w-[18px]" />
        @if ($counts['badge'] > 0)
            <span class="num absolute right-1 top-1 min-w-[18px] rounded-full bg-bad px-1 text-center text-[11px] font-semibold leading-[18px] text-white">{{ $counts['badge'] }}</span>
        @endif
    </button>

    @if ($open)
        <div class="absolute right-0 z-50 mt-2 w-[min(400px,calc(100vw-2rem))] rounded-xl border border-border bg-card p-3 shadow-xl" x-on:click.outside="$wire.set('open', false)">
            @if ($error)<p class="mb-2 rounded-lg bg-bad/10 p-2 text-sm text-bad" role="alert">{{ $error }}</p>@endif

            <div class="px-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ __('Ждут вашего решения') }}</div>
            @forelse ($awaiting as $r)
                <div class="mt-2 rounded-lg border border-border p-3" wire:key="r{{ $r->id }}">
                    <div class="text-sm font-medium">{{ $r->headline }}</div>
                    <div class="mt-1 text-sm text-muted-foreground">{{ $r->summary }}</div>
                    <div class="mt-1 text-xs text-muted-foreground">{{ __('до :time', ['time' => $r->expires_at->setTimezone($tz)->format('H:i')]) }}</div>
                    <div class="mt-2 flex gap-2">
                        <button type="button" wire:click="decide({{ $r->id }}, 'approve')" class="h-10 flex-1 rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Разрешить') }}</button>
                        <button type="button" wire:click="decide({{ $r->id }}, 'reject')" class="h-10 flex-1 rounded-lg border border-border text-sm">{{ __('Отклонить') }}</button>
                    </div>
                </div>
            @empty
                <p class="mt-2 px-1 text-sm text-muted-foreground">{{ __('Заявок нет') }}</p>
            @endforelse

            <div class="mt-4 flex items-center justify-between px-1">
                <span class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ __('Уведомления') }}</span>
                @if ($counts['notifications'] > 0)<button type="button" wire:click="markAllRead" class="text-xs font-medium text-primary">{{ __('Прочитать все') }}</button>@endif
            </div>
            @forelse ($notifications as $n)
                <div class="mt-2 rounded-lg px-1 text-sm {{ $n->read_at ? 'text-muted-foreground' : '' }}">
                    <div class="font-medium">{{ $n->title }}</div>
                    @if ($n->body)<div class="text-muted-foreground">{{ $n->body }}</div>@endif
                </div>
            @empty
                <p class="mt-2 px-1 text-sm text-muted-foreground">{{ __('Новых уведомлений нет') }}</p>
            @endforelse
        </div>
    @endif
</div>
