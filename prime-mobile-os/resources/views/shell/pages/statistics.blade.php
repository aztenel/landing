<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([__('Выручка'), __('Прибыль'), __('Чеков'), __('Средний чек')] as $tile)
            <div class="rounded-xl border border-border bg-card p-4">
                <div class="text-sm text-muted-foreground">{{ $tile }}</div>
                <div class="num mt-2 text-2xl font-semibold text-foreground">—</div>
            </div>
        @endforeach
    </div>
    <div class="rounded-xl border border-dashed border-border bg-card p-6 text-sm text-muted-foreground">
        {{ __('Цифры появятся, когда будет подключён модуль отчётов.') }}
    </div>
</x-filament-panels::page>
