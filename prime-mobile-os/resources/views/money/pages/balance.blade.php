<x-filament-panels::page>
@php
    $fmt = fn ($minor, $cur) => \App\Support\Money\Money::of((int) $minor, $cur)->format();
    $input = 'mt-1 w-full rounded-lg border-input bg-background text-base';
    $titles = ['collection' => __('Инкассация'), 'transfer' => __('Перевод между счетами'), 'fx' => __('Обмен валюты'), 'adjust' => __('Корректировка кассы'), 'accept' => __('Принять инкассацию')];
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <div class="mr-auto"><span class="text-sm text-muted-foreground">{{ __('Всего') }}</span> <span class="num text-2xl font-semibold">≈ {{ $fmt($total, 'KZT') }}</span></div>
        @if ($can['collect'])<button type="button" wire:click="openModal('collection')" class="h-10 rounded-lg border border-border bg-card px-3 text-sm">{{ __('Инкассация') }}</button>@endif
        @if ($can['transfer'])<button type="button" wire:click="openModal('transfer')" class="h-10 rounded-lg border border-border bg-card px-3 text-sm">{{ __('Перевод') }}</button>
            <button type="button" wire:click="openModal('fx')" class="h-10 rounded-lg border border-border bg-card px-3 text-sm">{{ __('Обмен валюты') }}</button>@endif
        @if ($can['adjust'])<button type="button" wire:click="openModal('adjust')" class="h-10 rounded-lg border border-border bg-card px-3 text-sm">{{ __('Корректировка') }}</button>@endif
    </div>
    @if ($error && ! $modal)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad">{{ $error }}</div>@elseif ($flash)<div class="text-sm text-ok" role="status">{{ $flash }}</div>@endif

    @foreach ($pending as $p)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3 text-sm" wire:key="p{{ $p->id }}">
            <span class="flex-1">{{ __('Инкассация :n от :who', ['n' => $pendingDocs[$p->document_id]?->number, 'who' => \App\Models\User::query()->find($pendingDocs[$p->document_id]?->posted_by)?->name]) }}</span>
            <span class="num font-semibold">{{ $fmt($p->amount, 'KZT') }}</span>
            <button type="button" wire:click="openModal('accept', {{ $p->document_id }})" class="h-9 rounded-lg bg-primary px-3 text-xs font-semibold text-white">{{ __('Принять') }}</button>
        </div>
    @endforeach

    @foreach ($groups as $key => $accounts)
        <section>
            <h2 class="mb-2 text-sm font-semibold text-muted-foreground">{{ str_starts_with($key, 'store:') ? __('Магазин «:s»', ['s' => $storeNames[(int) substr($key, 6)] ?? '']) : ($key === 'transit' ? __('В пути и зачёты') : __('Фирма')) }}</h2>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                @foreach ($accounts as $a)
                    @php($b = (int) ($balances[$a->id] ?? 0))
                    <button type="button" wire:click="show({{ $a->id }})" class="rounded-xl border bg-card p-4 text-left {{ $account?->id === $a->id ? 'border-primary ring-1 ring-primary' : 'border-border' }}" wire:key="a{{ $a->id }}">
                        <div class="truncate text-xs text-muted-foreground">{{ $a->name }}</div>
                        <div class="num mt-1 text-xl font-semibold">{{ $fmt($b, $a->currency->value) }}</div>
                        @if ($a->currency->value === 'USD' && $rate)<div class="num text-xs text-muted-foreground">≈ {{ $fmt((int) round($b * $rate), 'KZT') }}</div>@endif
                    </button>
                @endforeach
            </div>
        </section>
    @endforeach

    @if ($account)
        <section class="overflow-x-auto rounded-xl border border-border bg-card">
            <h3 class="border-b border-border px-4 py-3 font-semibold">{{ $account->name }}</h3>
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($entries as $e)
                        <tr class="border-b border-border last:border-0">
                            <td class="num px-4 py-2 text-xs text-muted-foreground">{{ $e->occurred_at?->setTimezone(\App\Support\Tenancy\CurrentOrganization::instance()->require()->timezone)->format('d.m H:i') }}</td>
                            <td class="num px-2 py-2 text-xs">{{ $docs[$e->document_id] ?? '' }}</td>
                            <td class="px-2 py-2 text-xs">{{ $articles[$e->article_id] ?? '' }}{{ $e->comment ? ' · '.$e->comment : '' }}</td>
                            <td class="num px-4 py-2 text-right {{ $e->amount < 0 ? 'text-bad' : 'text-ok' }}">{{ $e->amount > 0 ? '+' : '' }}{{ $fmt($e->amount, $account->currency->value) }}</td>
                        </tr>
                    @empty
                        <tr><td class="p-6 text-center text-muted-foreground">{{ __('Движений нет') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endif

    @if ($modal)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
            <div class="w-full space-y-3 rounded-t-2xl bg-card p-5 desk:w-[460px] desk:rounded-2xl" role="dialog">
                <h3 class="text-lg font-semibold">{{ $titles[$modal] }}</h3>
                @if ($modal !== 'accept')
                    <label class="block text-sm">{{ $modal === 'adjust' ? __('Касса') : __('Откуда') }}<select wire:model="f.from" class="{{ $input }}"><option value="">{{ __('Выберите') }}</option>@foreach ($all->when($modal === 'collection' || $modal === 'adjust', fn ($c) => $c->where('type.value', 'cash')) as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
                @endif
                @if (in_array($modal, ['transfer', 'fx'], true))
                    <label class="block text-sm">{{ __('Куда') }}<select wire:model="f.to" class="{{ $input }}"><option value="">{{ __('Выберите') }}</option>@foreach ($all as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
                @endif
                <label class="block text-sm">{{ $modal === 'adjust' ? __('Разница (минус — недостача)') : ($modal === 'accept' ? __('Пересчитали') : __('Сумма')) }}<input type="text" inputmode="decimal" wire:model="f.amount" class="{{ $input }} num"></label>
                @if ($modal === 'fx')<label class="block text-sm">{{ __('Получим') }}<input type="text" inputmode="decimal" wire:model="f.to_amount" class="{{ $input }} num"></label>@endif
                @if ($modal === 'transfer')<label class="block text-sm">{{ __('Комиссия (необязательно)') }}<input type="text" inputmode="decimal" wire:model="f.fee" class="{{ $input }} num"></label>@endif
                @if ($modal === 'accept')<label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="f.owner" class="h-4 w-4 rounded"> {{ __('Забрал себе (не в сейф)') }}</label>@endif
                <label class="block text-sm">{{ $modal === 'adjust' ? __('Причина') : __('Комментарий') }}<input type="text" wire:model="f.note" class="{{ $input }}"></label>
                @if ($error)<p class="text-sm text-bad">{{ $error }}</p>@endif
                <div class="flex justify-end gap-2"><button type="button" wire:click="$set('modal', null)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Отмена') }}</button><button type="button" wire:click="submit" class="h-10 rounded-lg bg-primary px-5 text-sm font-semibold text-white">{{ __('Провести') }}</button></div>
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
