<x-filament-panels::page>
<div class="mx-auto max-w-2xl space-y-4" wire:poll.10s>
    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>@endif
    @forelse ($awaiting as $r)
        <section class="space-y-3 rounded-2xl border border-border bg-card p-5" wire:key="r{{ $r->id }}">
            <div class="flex items-start justify-between gap-3"><h2 class="font-semibold">{{ $r->headline }}</h2><span class="num shrink-0 text-xs text-muted-foreground">{{ $r->created_at?->diffForHumans() }}</span></div>
            <p class="whitespace-pre-line text-sm">{{ $r->summary }}</p>
            @if ($r->reason_text)<p class="text-sm text-muted-foreground">«{{ $r->reason_text }}»</p>@endif
            @if ($r->needs_confirmation)<input type="password" wire:model="f.{{ $r->id }}.password" placeholder="{{ __('Ваш пароль — крупная заявка') }}" class="w-full rounded-lg border-input bg-background text-base">@endif
            <input type="text" wire:model="f.{{ $r->id }}.comment" placeholder="{{ __('Комментарий (необязательно)') }}" class="w-full rounded-lg border-input bg-background text-base">
            @if (array_intersect(['discount', 'debt', 'debt_overdue'], $r->kinds()))
                <div class="flex gap-2"><input type="text" inputmode="numeric" wire:model="f.{{ $r->id }}.value" placeholder="{{ __('Другая сумма, ₸') }}" class="num min-w-0 flex-1 rounded-lg border-input bg-background text-base">
                    <button type="button" wire:click="decide({{ $r->id }}, 'change')" class="h-11 rounded-lg border border-primary px-3 text-sm text-primary">{{ __('Изменить и разрешить') }}</button></div>
            @endif
            <div class="grid grid-cols-2 gap-2">
                <button type="button" wire:click="decide({{ $r->id }}, 'reject')" class="h-12 rounded-xl border border-bad/40 text-sm font-semibold text-bad">{{ __('Отклонить') }}</button>
                <button type="button" wire:click="decide({{ $r->id }}, 'approve')" class="h-12 rounded-xl bg-primary text-sm font-semibold text-white">{{ __('Разрешить') }}</button>
            </div>
        </section>
    @empty
        <p class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">{{ __('Заявок на решение нет') }}</p>
    @endforelse

    @if ($mine->isNotEmpty())
        <section class="rounded-xl border border-border bg-card p-4 text-sm">
            <h3 class="font-semibold">{{ __('Мои заявки') }}</h3>
            @foreach ($mine as $r)
                <div class="flex items-center gap-2 border-b border-border py-2 last:border-0"><span class="min-w-0 flex-1 truncate">{{ $r->headline }}</span><span class="text-xs text-muted-foreground">{{ $statuses[$r->status->value] ?? $r->status->value }}</span>
                    @if ($r->status->value === 'pending')<button type="button" wire:click="cancel({{ $r->id }})" class="text-xs text-bad">{{ __('Отменить') }}</button>@endif</div>
            @endforeach
        </section>
    @endif
</div>
</x-filament-panels::page>
