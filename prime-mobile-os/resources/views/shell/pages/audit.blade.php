<x-filament-panels::page>
    @php($field = 'mt-1 w-full rounded-lg border-input bg-background text-base focus:border-primary focus:ring-primary')
    <div class="grid gap-3 rounded-xl border border-border bg-card p-4 md:grid-cols-4">
        <label class="text-sm font-medium">{{ __('Сотрудник') }}
            <select wire:model.live="userId" class="{{ $field }}"><option value="">{{ __('Все') }}</option>@foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
        </label>
        <label class="text-sm font-medium">{{ __('Что') }}
            <select wire:model.live="kind" class="{{ $field }}"><option value="">{{ __('Всё') }}</option>@foreach (\App\Filament\Shell\Pages\AuditJournal::KINDS as $k => $label)<option value="{{ $k }}">{{ __($label) }}</option>@endforeach</select>
        </label>
        <label class="text-sm font-medium">{{ __('С') }}<input type="date" wire:model.live="from" class="{{ $field }}"></label>
        <label class="text-sm font-medium">{{ __('По') }}<input type="date" wire:model.live="to" class="{{ $field }}"></label>
    </div>

    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <table class="w-full text-sm">
            <thead class="bg-muted/60 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2.5">{{ __('Когда') }}</th><th class="px-4 py-2.5">{{ __('Кто') }}</th><th class="px-4 py-2.5">{{ __('Что сделал') }}</th><th class="hidden px-4 py-2.5 lg:table-cell">{{ __('Подробно') }}</th></tr></thead>
            <tbody>
                @forelse ($rows as $r)
                    @php($props = json_decode($r->properties ?? '{}', true) ?: [])
                    <tr class="border-t border-border align-top">
                        <td class="num whitespace-nowrap px-4 py-2.5 text-muted-foreground">{{ \Carbon\Carbon::parse($r->created_at)->setTimezone($tz)->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-2.5">{{ $names[$r->causer_id] ?? __('система') }}</td>
                        <td class="px-4 py-2.5"><span class="text-muted-foreground">{{ __(\App\Filament\Shell\Pages\AuditJournal::KINDS[$r->log_name] ?? $r->log_name) }}:</span> {{ __(\App\Filament\Shell\Pages\AuditJournal::EVENTS[$r->description] ?? $r->description) }}@if (! empty($props['number'])) · <span class="num">{{ $props['number'] }}</span>@endif</td>
                        <td class="hidden max-w-md truncate px-4 py-2.5 text-xs text-muted-foreground lg:table-cell" title="{{ json_encode($props, JSON_UNESCAPED_UNICODE) }}">{{ \Illuminate\Support\Str::limit(json_encode(\Illuminate\Support\Arr::except($props, ['organization_id']), JSON_UNESCAPED_UNICODE), 120) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">{{ __('Записей нет за этот период') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</x-filament-panels::page>
