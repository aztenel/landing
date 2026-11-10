{{-- Камера-сканер для iPhone (Code-128, EAN-13, QR), фонарик, ручной ввод — логика в resources/js/erp/scanner.js --}}
<div x-data x-cloak x-show="$store.pmScan && $store.pmScan.open" class="fixed inset-0 z-[65] flex flex-col bg-black" role="dialog" aria-modal="true" aria-label="{{ __('Сканер') }}">
    <div class="flex items-center justify-between p-3 pt-[max(.75rem,env(safe-area-inset-top))] text-white">
        <span class="text-sm font-medium">{{ __('Наведите на штрихкод или IMEI') }}</span>
        <button type="button" class="grid h-11 w-11 place-items-center rounded-full bg-white/10" x-on:click="$store.pmScan.close()" aria-label="{{ __('Закрыть') }}"><x-ui::icon name="x" class="h-5 w-5" /></button>
    </div>
    <div class="relative flex-1 overflow-hidden">
        <video id="pm-scan-video" class="h-full w-full object-cover" playsinline muted></video>
        <div class="pointer-events-none absolute inset-x-8 top-1/2 h-24 -translate-y-1/2 rounded-xl border-2 border-white/80"></div>
        <p class="absolute inset-x-4 bottom-4 text-center text-sm text-white" x-show="$store.pmScan.message" x-text="$store.pmScan.message"></p>
    </div>
    <div class="space-y-2 bg-black p-3 pb-[max(.75rem,env(safe-area-inset-bottom))]">
        <div class="flex gap-2">
            <button type="button" class="h-12 flex-1 rounded-lg bg-white/10 text-sm font-medium text-white" x-on:click="$store.pmScan.toggleTorch()" x-show="$store.pmScan.torchAvailable">{{ __('Фонарик') }}</button>
        </div>
        <form class="flex gap-2" x-on:submit.prevent="$store.pmScan.manual($refs.manual.value); $refs.manual.value = ''">
            <input x-ref="manual" type="text" inputmode="numeric" placeholder="{{ __('Ввести IMEI вручную') }}" class="h-12 flex-1 rounded-lg border-0 bg-white text-base text-black">
            <button type="submit" class="h-12 rounded-lg bg-primary px-4 text-sm font-semibold text-white">{{ __('Готово') }}</button>
        </form>
    </div>
</div>
