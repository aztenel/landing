<x-filament-panels::page>
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <div class="-mx-1 flex flex-1 gap-1 overflow-x-auto border-b border-border px-1 text-sm">
            @foreach (['posted' => __('Проведённые'), 'drafts' => __('Черновики'), 'no_cost' => __('Без себестоимости').' ('.$noCost.')'] as $k => $label)
                <button type="button" wire:click="$set('tabName', '{{ $k }}')" class="h-10 shrink-0 px-3 {{ $tabName === $k ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ $label }}</button>
            @endforeach
        </div>
        @if ($canSupplier)<select wire:model.live="supplier_id" class="h-10 rounded-lg border-border bg-card py-0 text-sm"><option value="">{{ __('Все поставщики') }}</option>@foreach ($suppliers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>@endif
        <a href="{{ \App\Filament\Intake\Pages\NewIntake::getUrl() }}" class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white"><x-ui::icon name="plus" class="h-4 w-4" /> {{ __('Новый приход') }}</a>
    </div>
    @if ($error)<div class="rounded-lg border border-bad/30 bg-bad/5 px-4 py-2.5 text-sm text-bad" role="alert">{{ $error }}</div>@endif
    <div class="overflow-x-auto rounded-xl border border-border bg-card">
        <table class="w-full text-sm">
            <thead class="border-b border-border text-left text-xs text-muted-foreground"><tr>
                <th class="px-4 py-3">№</th><th class="px-2 py-3">{{ __('Дата') }}</th>@if ($canSupplier)<th class="px-2 py-3">{{ __('Поставщик') }}</th>@endif
                <th class="px-2 py-3 text-right">{{ __('Телефоны / аксессуары') }}</th>@if ($canCost)<th class="px-2 py-3 text-right">{{ __('Сумма') }}</th>@endif<th class="hidden px-2 py-3 md:table-cell">{{ __('Кто') }}</th><th class="px-4 py-3"></th>
            </tr></thead>
            <tbody>
                @forelse ($docs as $d)
                    @php($p = $purchases[$d->id] ?? null)
                    <tr class="border-b border-border last:border-0" wire:key="d{{ $d->id }}">
                        <td class="num px-4 py-3">{{ $d->number ?? __('черновик') }}
                            @if ($d->status->value === 'posted')
                                <span class="ml-1 inline-flex gap-2 text-xs"><a href="{{ route('erp.print.invoice', $d) }}" target="_blank" class="text-primary">{{ __('накладная') }}</a>
                                @if (\App\Support\Ui\Access::allows('label.print'))<a href="{{ route('erp.print.labels', ['doc' => $d->id]) }}" target="_blank" class="text-primary">{{ __('этикетки') }}</a>@endif</span>
                            @endif @if ($d->status->value === 'reversed')<span class="ml-1 rounded-full bg-muted px-2 text-xs">{{ __('сторно') }}</span>@endif</td>
                        <td class="num px-2 py-3 text-muted-foreground">{{ ($d->posted_at ?? $d->created_at)?->setTimezone(\App\Support\Tenancy\CurrentOrganization::instance()->require()->timezone)->format('d.m.Y H:i') }}</td>
                        @if ($canSupplier)<td class="px-2 py-3">{{ $suppliers[$p?->supplier_id] ?? '—' }}</td>@endif
                        <td class="num px-2 py-3 text-right">{{ (int) ($lines[$d->id]->units ?? 0) }} / {{ (int) ($lines[$d->id]->acc ?? 0) }}</td>
                        @if ($canCost)<td class="num px-2 py-3 text-right">{{ $p ? \App\Support\Money\Money::of((int) $p->total, $p->currency)->format() : '' }}@if ($p && $p->credit_amount)<span class="block text-xs text-warn">{{ __('в долг') }} {{ \App\Support\Money\Money::of((int) $p->credit_amount, $p->currency)->format() }}</span>@endif</td>@endif
                        <td class="hidden px-2 py-3 text-xs text-muted-foreground md:table-cell">{{ $users[$d->posted_by ?? $d->created_by] ?? '' }}</td>
                        <td class="px-4 py-3 text-right">@if ($canReverse && $d->status->value === 'posted')<button type="button" wire:click="$set('reverseId', {{ $d->id }})" class="text-xs text-bad">{{ __('Сторно') }}</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-10 text-center text-muted-foreground">{{ __('Приходов пока нет.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $docs->links() }}</div>

    @if ($reverseId)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 desk:items-center">
            <div class="w-full rounded-t-2xl bg-card p-5 desk:w-[440px] desk:rounded-2xl" role="dialog">
                <h3 class="text-lg font-semibold">{{ __('Сторнировать приход?') }}</h3>
                <p class="mt-1 text-sm text-muted-foreground">{{ __('Телефоны уйдут из учёта, долг поставщику и оплаты вернутся. Если телефон уже продан — сначала сторнируйте продажу.') }}</p>
                <input type="text" wire:model="reason" placeholder="{{ __('Причина (не короче 5 символов)') }}" class="mt-3 w-full rounded-lg border-input bg-background text-base">
                @if ($error)<p class="mt-2 text-sm text-bad">{{ $error }}</p>@endif
                <div class="mt-4 flex justify-end gap-2"><button type="button" wire:click="$set('reverseId', null)" class="h-10 rounded-lg border border-border px-4 text-sm">{{ __('Отмена') }}</button><button type="button" wire:click="reverse" class="h-10 rounded-lg bg-bad px-5 text-sm font-semibold text-white">{{ __('Сторнировать') }}</button></div>
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
