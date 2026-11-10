<div x-data x-on:pm-scan.window="$wire.addCode($event.detail.code)">
    <div class="flex gap-2">
        <input type="text" wire:model="code" wire:keydown.enter="addCode" data-pm-scan-target placeholder="{{ __('Сканируйте IMEI или штрихкод') }}" class="h-11 min-w-0 flex-1 rounded-lg border-2 border-primary bg-background text-base">
        <button type="button" class="flex h-11 w-11 items-center justify-center rounded-lg bg-primary text-white desk:hidden" x-on:click="PM.scanner.open()" aria-label="{{ __('Камера') }}"><x-ui::icon name="camera" class="h-5 w-5" /></button>
    </div>
    <div class="mt-2 divide-y divide-border">
        @foreach ($this->goodsRows() as $g)
            <div class="flex items-center gap-3 py-2 text-sm" wire:key="g{{ $g['i'] }}">
                <x-ui::icon :name="$g['kind'] === 'unit' ? 'phone' : 'box'" class="h-4 w-4 text-muted-foreground" />
                <span class="min-w-0 flex-1 truncate">{{ $g['title'] }} @if ($g['code'])<span class="num text-xs text-muted-foreground">{{ $g['code'] }} · {{ $g['status'] }}</span>@endif</span>
                @if ($g['kind'] === 'accessory')<input type="number" min="1" wire:model.live="goods.{{ $g['i'] }}.qty" class="num h-9 w-20 rounded-lg border-input text-sm">@endif
                <button type="button" wire:click="dropGood({{ $g['i'] }})" class="text-muted-foreground" aria-label="{{ __('Убрать') }}">×</button>
            </div>
        @endforeach
    </div>
</div>
