{{-- Place of the bell (TZ 04 Э28). The approvals module renders its Livewire bell into ShellHook::TOPBAR_BELL; until then — a quiet icon. --}}
@php($hook = \Filament\Support\Facades\FilamentView::renderHook(\App\Support\Ui\ShellHook::TOPBAR_BELL))
@if (trim((string) $hook) !== '')
    {!! $hook !!}
@else
    <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-muted-foreground" title="{{ __('Уведомления') }}">
        <x-ui::icon name="bell" class="h-[18px] w-[18px]" />
    </span>
@endif
