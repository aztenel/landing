<x-filament-panels::page>
    {{-- Э25: вкладки «Сотрудники», «Роли», «Устройства» --}}
    <div class="flex gap-1 rounded-lg bg-muted p-1 text-sm font-medium" role="tablist">
        @if ($can['staff'])
            <button type="button" role="tab" wire:click="$set('tab', 'staff')" class="h-10 flex-1 rounded-md {{ $tab === 'staff' ? 'bg-card shadow-sm' : 'text-muted-foreground' }}">{{ __('Сотрудники') }}</button>
        @endif
        @if ($can['roles'])
            <button type="button" role="tab" wire:click="$set('tab', 'roles')" class="h-10 flex-1 rounded-md {{ $tab === 'roles' ? 'bg-card shadow-sm' : 'text-muted-foreground' }}">{{ __('Роли и права') }}</button>
        @endif
        @if ($can['devices'])
            <button type="button" role="tab" wire:click="$set('tab', 'devices')" class="h-10 flex-1 rounded-md {{ $tab === 'devices' ? 'bg-card shadow-sm' : 'text-muted-foreground' }}">{{ __('Устройства') }}</button>
        @endif
    </div>

    @if ($inviteLink)
        <div class="rounded-xl border border-primary/30 bg-primary/5 p-4 text-sm">
            <div class="font-medium">{{ __('Ссылка для сотрудника (действует 48 часов, открывается один раз):') }}</div>
            <div class="mt-2 flex gap-2">
                <input type="text" readonly value="{{ $inviteLink }}" class="num w-full rounded-lg border-input bg-background text-sm" onclick="this.select()">
                <button type="button" wire:click="$set('inviteLink', null)" class="h-10 rounded-lg border border-border px-3">{{ __('Готово') }}</button>
            </div>
        </div>
    @endif

    @if ($tab === 'staff' && $can['staff'])
        <div class="grid gap-4 xl:grid-cols-[1fr_360px]">
            <div class="overflow-hidden rounded-xl border border-border bg-card">
                <table class="w-full text-sm">
                    <thead class="bg-muted/60 text-left text-xs text-muted-foreground">
                        <tr><th class="px-4 py-2.5">{{ __('Сотрудник') }}</th><th class="px-4 py-2.5">{{ __('Роль') }}</th><th class="hidden px-4 py-2.5 md:table-cell">{{ __('Магазины') }}</th><th class="px-4 py-2.5">{{ __('Статус') }}</th><th class="px-4 py-2.5"></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($members as $m)
                            @php($r = $staff->roleOf($m->user))
                            <tr class="border-t border-border" wire:key="m{{ $m->id }}">
                                <td class="px-4 py-3"><div class="flex items-center gap-2.5"><x-ui::avatar :name="$m->user->name" /><div><div class="font-medium">{{ $m->user->name }}</div><div class="num text-xs text-muted-foreground">{{ $phone($m->user->phone) }}</div></div></div></td>
                                <td class="px-4 py-3">{{ $r?->title }}</td>
                                <td class="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                    @if ($r?->is_owner) {{ __('все') }} @else
                                        {{ $m->stores->map(fn ($s) => optional($stores->firstWhere('id', $s->store_id))->name.($s->can_finance ? '' : ' · '.__('без финансов')))->implode(', ') }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs {{ $m->status->value === 'active' ? 'bg-ok/10 text-ok' : 'bg-muted text-muted-foreground' }}">{{ ['active' => __('работает'), 'invited' => __('приглашён'), 'blocked' => __('заблокирован'), 'dismissed' => __('уволен')][$m->status->value] ?? $m->status->value }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @unless ($r?->is_owner)
                                        <div class="flex justify-end gap-1">
                                            @if ($m->status->value === 'active')
                                                <button type="button" wire:click="staffAction({{ $m->id }}, 'password')" class="h-9 rounded-lg px-2.5 text-xs hover:bg-muted">{{ __('Сбросить пароль') }}</button>
                                                <button type="button" wire:click="staffAction({{ $m->id }}, 'block')" wire:confirm="{{ __('Заблокировать сотрудника? Он сразу потеряет доступ') }}" class="h-9 rounded-lg px-2.5 text-xs text-bad hover:bg-bad/10">{{ __('Заблокировать') }}</button>
                                            @elseif ($m->status->value === 'blocked')
                                                <button type="button" wire:click="staffAction({{ $m->id }}, 'unblock')" class="h-9 rounded-lg px-2.5 text-xs hover:bg-muted">{{ __('Вернуть доступ') }}</button>
                                                <button type="button" wire:click="staffAction({{ $m->id }}, 'dismiss')" wire:confirm="{{ __('Уволить? История продаж сохранится') }}" class="h-9 rounded-lg px-2.5 text-xs text-bad hover:bg-bad/10">{{ __('Уволить') }}</button>
                                            @elseif ($m->status->value === 'dismissed')
                                                <button type="button" wire:click="staffAction({{ $m->id }}, 'restore')" class="h-9 rounded-lg px-2.5 text-xs hover:bg-muted">{{ __('Восстановить') }}</button>
                                            @endif
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <form wire:submit="invite" class="space-y-3 rounded-xl border border-border bg-card p-4">
                <h2 class="font-semibold">{{ __('Пригласить сотрудника') }}</h2>
                <label class="block text-sm font-medium">{{ __('Имя и фамилия') }}
                    <input type="text" wire:model="inviteName" required class="mt-1 w-full rounded-lg border-input bg-background text-base">
                </label>
                <label class="block text-sm font-medium">{{ __('Телефон') }}
                    <input type="tel" inputmode="tel" wire:model="invitePhone" required placeholder="+7 701 000 00 00" class="num mt-1 w-full rounded-lg border-input bg-background text-base">
                </label>
                <label class="block text-sm font-medium">{{ __('Роль') }}
                    <select wire:model="inviteRole" required class="mt-1 w-full rounded-lg border-input bg-background text-base">
                        <option value="">{{ __('Выберите') }}</option>
                        @foreach ($roles as $r)
                            @unless ($r->is_owner)<option value="{{ $r->id }}">{{ $r->title }}</option>@endunless
                        @endforeach
                    </select>
                </label>
                <fieldset class="text-sm"><legend class="font-medium">{{ __('Магазины') }}</legend>
                    @foreach ($stores as $s)
                        <label class="mt-1 flex min-h-[40px] items-center gap-2"><input type="checkbox" value="{{ $s->id }}" wire:model="inviteStores" class="h-5 w-5 rounded border-input text-primary"> {{ $s->name }}</label>
                    @endforeach
                </fieldset>
                <button type="submit" class="h-11 w-full rounded-lg bg-primary text-sm font-semibold text-white">{{ __('Получить ссылку-приглашение') }}</button>
            </form>
        </div>
    @endif

    @if ($tab === 'roles' && $can['roles'])
        <div class="flex flex-wrap gap-2">
            @foreach ($roles as $r)
                <button type="button" wire:click="loadRole({{ $r->id }})" class="h-10 rounded-lg border px-3 text-sm {{ $roleId === $r->id ? 'border-primary bg-primary/5 font-semibold text-primary' : 'border-border' }}">{{ $r->title }}</button>
            @endforeach
        </div>
        @if ($role && $role->is_owner)
            <div class="rounded-xl border border-border bg-card p-4 text-sm text-muted-foreground">{{ __('Владелец имеет все права без лимитов — их нельзя отключить.') }}</div>
        @elseif ($role)
            <div class="space-y-4">
                @foreach ($matrix as $group)
                    <section class="overflow-hidden rounded-xl border border-border bg-card">
                        <h3 class="bg-muted/60 px-4 py-2.5 text-sm font-semibold">{{ $group['title'] }}</h3>
                        @foreach ($group['items'] as $item)
                            <div class="flex flex-wrap items-center gap-3 border-t border-border px-4 py-3 text-sm" wire:key="p{{ $item['code'] }}">
                                <label class="flex min-w-[260px] flex-1 items-start gap-3">
                                    <input type="checkbox" wire:model="state.{{ \App\Filament\Shell\Pages\Team::key($item['code']) }}.on" class="mt-0.5 h-5 w-5 rounded border-input text-primary">
                                    <span><span class="block">{{ $item['title'] }}</span>@if ($item['hint'])<span class="block text-xs text-muted-foreground">{{ $item['hint'] }}</span>@endif</span>
                                </label>
                                <label class="flex items-center gap-2 text-xs text-muted-foreground"><input type="checkbox" wire:model="state.{{ \App\Filament\Shell\Pages\Team::key($item['code']) }}.approval" class="h-4 w-4 rounded border-input text-primary"> {{ __('только заявкой') }}</label>
                                @foreach ($item['limits'] as $limit)
                                    <label class="flex items-center gap-2 text-xs text-muted-foreground">{{ $limit['title'] }}
                                        <input type="text" inputmode="decimal" wire:model="limits.{{ $limit['code'] }}" placeholder="{{ __('без лимита') }}" class="num h-9 w-28 rounded-lg border-input bg-background text-sm">
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                    </section>
                @endforeach
                <div class="sticky bottom-3 flex justify-end">
                    <button type="button" wire:click="saveRole" class="h-11 rounded-lg bg-primary px-6 text-sm font-semibold text-white shadow-lg">{{ __('Сохранить роль') }}</button>
                </div>
            </div>
        @endif
    @endif

    @if ($tab === 'devices' && $can['devices'])
        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="bg-muted/60 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2.5">{{ __('Устройство') }}</th><th class="px-4 py-2.5">{{ __('Последний вход') }}</th><th class="px-4 py-2.5"></th></tr></thead>
                <tbody>
                    @forelse ($devices as $d)
                        <tr class="border-t border-border" wire:key="d{{ $d->id }}">
                            <td class="px-4 py-3"><div class="font-medium">{{ $d->label() }}</div><div class="text-xs text-muted-foreground">{{ $d->lastUser?->name }}</div></td>
                            <td class="num px-4 py-3 text-muted-foreground">{{ $d->last_seen_at?->setTimezone(\App\Support\Tenancy\CurrentOrganization::instance()->require()->timezone)->format('d.m.Y H:i') }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($d->isDisabled())
                                    <button type="button" wire:click="deviceAction({{ $d->id }}, 'enable')" class="h-9 rounded-lg px-2.5 text-xs hover:bg-muted">{{ __('Включить') }}</button>
                                @else
                                    <button type="button" wire:click="deviceAction({{ $d->id }}, 'disable')" wire:confirm="{{ __('Отключить устройство? С него выйдут все сотрудники') }}" class="h-9 rounded-lg px-2.5 text-xs text-bad hover:bg-bad/10">{{ __('Отключить') }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-muted-foreground">{{ __('Устройства появятся после первого входа сотрудников') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
