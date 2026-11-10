<x-filament-panels::page>
@php($money = fn ($t) => \App\Support\Money\Money::kztMinor((int) $t)->format())
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <input type="month" wire:model.live="month" class="h-10 rounded-lg border-border bg-card text-sm">
        <span class="mr-auto text-sm text-muted-foreground">{{ __('Итого') }} <span class="num font-semibold text-foreground">{{ $money($expenses->sum('total_kzt')) }}</span></span>
        @if ($canCreate)<button type="button" wire:click="newExpense" class="h-10 rounded-lg bg-primary px-4 text-sm font-semibold text-white">+ {{ __('Расход') }}</button>@endif
    </div>
    @if ($error && ! $form)<div class="rounded-lg bg-bad/5 px-4 py-2.5 text-sm text-bad">{{ $error }}</div>@elseif ($flash)<div class="text-sm text-ok" role="status">{{ $flash }}</div>@endif
    <div class="grid grid-cols-[minmax(0,1fr)] gap-4 desk:grid-cols-[minmax(0,1fr)_340px]">
        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border text-left text-xs text-muted-foreground"><tr><th class="px-4 py-3">{{ __('Дата') }}</th><th class="px-2 py-3">{{ __('Статья') }}</th><th class="hidden px-2 py-3 md:table-cell">{{ __('Кто') }}</th><th class="px-4 py-3 text-right">{{ __('Сумма') }}</th></tr></thead>
                <tbody>
                    @forelse ($docs as $d)
                        @php($e = $expenses[$d->id] ?? null)
                        <tr class="border-b border-border last:border-0"><td class="num px-4 py-2.5 text-xs text-muted-foreground">{{ $d->occurred_at?->format('d.m') }}</td>
                            <td class="px-2 py-2.5">{{ $articleNames[$e?->article_id] ?? '' }}<span class="block text-xs text-muted-foreground">{{ $e?->description }}</span></td>
                            <td class="hidden px-2 py-2.5 text-xs text-muted-foreground md:table-cell">{{ $users[$d->posted_by] ?? '' }}</td>
                            <td class="num px-4 py-2.5 text-right font-semibold">{{ $money($e?->total_kzt) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="p-10 text-center text-muted-foreground">{{ __('Расходов за период нет') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <section class="rounded-xl border border-border bg-card p-4 text-sm">
            <h3 class="font-semibold">{{ __('По статьям') }}</h3>
            @php($max = max(1, (int) $byArticle->max()))
            @foreach ($byArticle as $id => $sum)
                <div class="mt-2"><div class="flex justify-between"><span>{{ $articleNames[$id] ?? '' }}</span><span class="num">{{ $money($sum) }}</span></div>
                    <div class="mt-1 h-1.5 rounded-full bg-muted"><div class="h-1.5 rounded-full bg-primary" style="width: {{ (int) round($sum * 100 / $max) }}%"></div></div>
                    @if (isset($prev[$id]))<div class="text-xs text-muted-foreground">{{ __('прошлый месяц') }} {{ $money($prev[$id]) }}</div>@endif</div>
            @endforeach
        </section>
    </div>

    @if ($form !== null)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
            <div class="w-full space-y-3 rounded-t-2xl bg-card p-5 desk:w-[460px] desk:rounded-2xl" role="dialog">
                <h3 class="text-lg font-semibold">{{ __('Расход') }}</h3>
                <select wire:model="form.article_id" class="w-full rounded-lg border-input bg-background text-base"><option value="">{{ __('Статья') }}</option>@foreach ($articles as $a)<option value="{{ $a->id }}">{{ $a->name_ru }}</option>@endforeach</select>
                <input type="text" inputmode="decimal" wire:model="form.amount" placeholder="{{ __('Сумма') }}" class="num w-full rounded-lg border-input bg-background text-base">
                <select wire:model="form.account_id" class="w-full rounded-lg border-input bg-background text-base"><option value="">{{ __('Откуда') }}</option>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select>
                <input type="text" wire:model="form.description" placeholder="{{ __('Описание') }}" class="w-full rounded-lg border-input bg-background text-base">
                <p class="text-xs text-muted-foreground">{{ __('Сверх лимита роли — заявкой владельцу. Фото чека — позже.') }}</p>
                @if ($error)<p class="text-sm text-bad">{{ $error }}</p>@endif
                <div class="flex justify-end gap-2"><button type="button" wire:click="$set('form', null)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Отмена') }}</button><button type="button" wire:click="save" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Записать расход') }}</button></div>
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
