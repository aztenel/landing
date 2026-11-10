<x-filament-panels::page>
@php
    $money = fn ($t) => \App\Support\Money\Money::kztMinor((int) $t)->format();
    $kinds = ['sale_credit' => 'в долг', 'payment_in' => 'оплата', 'adjustment' => 'корректировка', 'return' => 'возврат', 'opening' => 'остаток на начало', 'prepayment' => 'аванс', 'prepayment_use' => 'зачёт аванса', 'reversal' => 'сторно',
        'accrual' => 'начислено', 'spend' => 'оплата бонусами', 'return_of_spent' => 'возврат бонусов', 'cancel_accrual' => 'отмена начисления', 'expire' => 'сгорание', 'manual' => 'вручную'];
@endphp
<div class="grid grid-cols-[minmax(0,1fr)] gap-4 xl:grid-cols-[minmax(0,1fr)_480px]">
    <div class="space-y-3">
        <div class="flex flex-wrap gap-2">
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="{{ __('Имя или телефон') }}" class="h-10 min-w-[200px] flex-1 rounded-lg border-border bg-card text-base md:max-w-sm md:text-sm">
            <select wire:model.live="filter" class="h-10 rounded-lg border-border bg-card py-0 text-sm"><option value="">{{ __('Все') }}</option><option value="debt">{{ __('есть долг') }}</option><option value="overdue">{{ __('просрочен долг') }}</option><option value="bonus">{{ __('есть бонусы') }}</option></select>
        </div>
        <div class="overflow-x-auto rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border text-left text-xs text-muted-foreground"><tr><th class="px-4 py-3">{{ __('Клиент') }}</th><th class="px-2 py-3 text-right">{{ __('Бонусы') }}</th><th class="px-4 py-3 text-right">{{ __('Долг') }}</th></tr></thead>
                <tbody>
                    @forelse ($customers as $x)
                        @php($d = $debts[$x->id] ?? null)
                        <tr class="cursor-pointer border-b border-border last:border-0 hover:bg-muted/40 {{ $c?->id === $x->id ? 'bg-primary/5' : '' }}" wire:click="open({{ $x->id }})" wire:key="x{{ $x->id }}">
                            <td class="px-4 py-3"><span class="block font-medium">{{ $x->name }}</span><span class="num block text-xs text-muted-foreground">{{ $x->phone }}</span></td>
                            <td class="num px-2 py-3 text-right">{{ (int) ($bonuses[$x->id] ?? 0) ?: '' }}</td>
                            <td class="num px-4 py-3 text-right {{ $d && $d->nearest_due_date && $d->nearest_due_date->lt(today()) ? 'text-bad font-semibold' : '' }}">{{ $d && $d->balance > 0 ? $money($d->balance) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="p-10 text-center text-muted-foreground">{{ __('Клиентов пока нет. Клиент добавляется в кассе (F3) при продаже') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $customers->links() }}</div>
    </div>

    <div>
        @if ($c)
            <div class="space-y-3 rounded-xl border border-border bg-card p-4 text-sm xl:sticky xl:top-4">
                <div class="flex items-start gap-3">
                    <x-ui::avatar :name="$c->name" />
                    <div class="min-w-0 flex-1"><h2 class="text-lg font-semibold">{{ $c->name }}</h2><a href="tel:{{ $c->phone }}" class="num text-muted-foreground">{{ $c->phone }}</a></div>
                    <button type="button" wire:click="$set('openId', null)" class="text-muted-foreground">×</button>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($c->phone)<a href="https://wa.me/{{ preg_replace('/\D/', '', $c->phone) }}" target="_blank" class="inline-flex h-9 items-center rounded-lg border border-border px-3 text-xs">WhatsApp</a>@endif
                    @if ($canEdit && ! $c->anonymized_at)<button type="button" wire:click="edit" class="h-9 rounded-lg border border-border px-3 text-xs">{{ __('Изменить') }}</button>@endif
                </div>
                @if ($form !== null)
                    <form wire:submit="save" class="space-y-2 rounded-lg bg-muted/50 p-3">
                        <input type="text" wire:model="form.name" class="w-full rounded-lg border-input bg-background text-base">@error('form.name')<p class="text-xs text-bad">{{ $message }}</p>@enderror
                        <input type="tel" wire:model="form.phone" class="num w-full rounded-lg border-input bg-background text-base">@error('form.phone')<p class="text-xs text-bad">{{ $message }}</p>@enderror
                        <textarea wire:model="form.comment" rows="2" placeholder="{{ __('Комментарий') }}" class="w-full rounded-lg border-input bg-background text-base"></textarea>
                        <div class="flex justify-end gap-2"><button type="button" wire:click="$set('form', null)" class="h-9 rounded-lg border border-border px-3 text-xs">{{ __('Отмена') }}</button><button type="submit" class="h-9 rounded-lg bg-primary px-4 text-xs text-white">{{ __('Сохранить') }}</button></div>
                    </form>
                @endif
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-lg bg-muted p-2"><div class="num font-semibold">{{ $saleDocs->where('status.value', 'posted')->count() }}</div><div class="text-xs text-muted-foreground">{{ __('покупок') }}</div><div class="num text-xs">{{ $money($sales->sum('total')) }}</div></div>
                    <div class="rounded-lg bg-muted p-2"><div class="num font-semibold">{{ number_format((int) ($cBonus?->available ?? 0), 0, '', ' ') }}</div><div class="text-xs text-muted-foreground">{{ __('бонусы') }}</div>@if ($cBonus?->pending)<div class="text-xs">+{{ number_format((int) $cBonus->pending, 0, '', ' ') }} {{ __('ждут') }}</div>@endif</div>
                    <div class="rounded-lg p-2 {{ $cDebt && $cDebt->balance > 0 && $cDebt->nearest_due_date?->lt(today()) ? 'bg-bad/10 text-bad' : 'bg-muted' }}"><div class="num font-semibold">{{ $cDebt && $cDebt->balance > 0 ? $money($cDebt->balance) : '0' }}</div><div class="text-xs">{{ __('долг') }}{{ $cDebt?->nearest_due_date ? ' '.__('до').' '.$cDebt->nearest_due_date->format('d.m') : '' }}</div></div>
                </div>
                <div class="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1 text-xs">
                    @foreach (['sales' => __('Покупки'), 'phones' => __('Телефоны и гарантии'), 'debt' => __('Долги'), 'bonus' => __('Бонусы'), 'tradein' => 'Trade-in', 'orders' => __('Заказы и обращения'), 'data' => __('Данные')] as $k => $l)
                        <button type="button" wire:click="$set('tabName', '{{ $k }}')" class="h-9 shrink-0 px-2 {{ $tabName === $k ? 'border-b-2 border-primary font-semibold' : 'text-muted-foreground' }}">{{ $l }}</button>
                    @endforeach
                </div>
                <div class="max-h-80 overflow-y-auto text-xs">
                    @if ($tabName === 'sales')
                        @forelse ($saleDocs as $d)<div class="num flex justify-between border-b border-border py-1.5"><span>{{ $d->number }} · {{ $d->posted_at?->format('d.m.Y') }}{{ $d->status->value === 'reversed' ? ' · '.__('сторно') : '' }}</span><span>{{ $money($sales[$d->id]?->total) }}</span></div>@empty<p class="text-muted-foreground">{{ __('Покупок нет') }}</p>@endforelse
                    @elseif ($tabName === 'phones')
                        @forelse ($phones as $u)<div class="flex justify-between border-b border-border py-1.5"><a href="{{ route('erp.stock.units.show', $u->public_code ?? $u->id) }}">{{ $u->variant?->title() }}</a><span class="num text-muted-foreground">{{ $u->imei1 }}</span></div>@empty<p class="text-muted-foreground">{{ __('Телефонов нет') }}</p>@endforelse
                    @elseif ($tabName === 'debt')
                        @forelse ($debtEntries as $e)<div class="num flex justify-between border-b border-border py-1.5"><span>{{ $e->occurred_at?->format('d.m.Y') }} · {{ __($kinds[$e->kind?->value ?? $e->kind] ?? ($e->kind?->value ?? $e->kind)) }}{{ $e->due_date ? ' · '.__('срок').' '.$e->due_date->format('d.m') : '' }}</span><span class="{{ $e->amount > 0 ? 'text-bad' : 'text-ok' }}">{{ $e->amount > 0 ? '+' : '' }}{{ $money($e->amount) }}</span></div>@empty<p class="text-muted-foreground">{{ __('Долгов не было') }}</p>@endforelse
                    @elseif ($tabName === 'bonus')
                        @forelse ($bonusEntries as $e)<div class="num flex justify-between border-b border-border py-1.5"><span>{{ $e->occurred_at?->format('d.m.Y') }} · {{ __($kinds[$e->kind?->value ?? $e->kind] ?? ($e->kind?->value ?? $e->kind)) }}</span><span>{{ $e->amount > 0 ? '+' : '' }}{{ number_format((int) $e->amount, 0, '', ' ') }}</span></div>@empty<p class="text-muted-foreground">{{ __('Бонусов не было') }}</p>@endforelse
                    @elseif ($tabName === 'tradein')
                        @forelse ($acts as $a)<div class="num flex justify-between border-b border-border py-1.5"><span>{{ $a->imei1 }} · {{ $a->grade }}</span><span>{{ $money($a->amount) }}</span></div>@empty<p class="text-muted-foreground">{{ __('Trade-in не сдавал') }}</p>@endforelse
                    @elseif ($tabName === 'orders')
                        @foreach ($orders as $o)<div class="flex justify-between border-b border-border py-1.5"><span>{{ __('Заказ') }} · {{ $o->status }}</span><span class="num">{{ $money($o->price) }}</span></div>@endforeach
                        @foreach ($cases as $w)<div class="flex justify-between border-b border-border py-1.5"><span>{{ $w->number }}</span><span>{{ \App\Domain\Warehouse\Models\WarrantyCase::STATUSES[$w->status] ?? $w->status }}</span></div>@endforeach
                        @if ($orders->isEmpty() && $cases->isEmpty())<p class="text-muted-foreground">{{ __('Нет') }}</p>@endif
                    @else
                        <p>{{ __('Согласие на данные') }}: {{ $c->pd_consent_at ? $c->pd_consent_at->format('d.m.Y').' · '.$c->pd_consent_method : __('нет (клиент из sfon)') }}</p>
                        @if ($c->anonymized_at)<p class="mt-2 text-muted-foreground">{{ __('Обезличен') }} {{ $c->anonymized_at->format('d.m.Y') }}</p>
                        @elseif ($canPrivacy)<button type="button" wire:click="anonymize" wire:confirm="{{ __('Имя и телефон будут заменены на «Клиент №…». Покупки и суммы останутся. Отменить нельзя') }}" class="mt-2 h-9 rounded-lg px-3 text-xs text-bad hover:bg-bad/10">{{ __('Обезличить') }}</button>@endif
                    @endif
                </div>
            </div>
        @else
            <p class="hidden rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground xl:block">{{ __('Выберите клиента') }}</p>
        @endif
    </div>
</div>
</x-filament-panels::page>
