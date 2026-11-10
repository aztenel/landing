{{-- Hotkey hint, e.g. <x-ui::kbd>F9</x-ui::kbd> (TZ 04 §0.6) --}}
<kbd {{ $attributes->merge(['class' => 'inline-flex min-w-[1.75rem] items-center justify-center rounded-md border border-border bg-background px-1.5 py-0.5 font-mono text-[11px] font-medium text-muted-foreground']) }}>{{ $slot }}</kbd>
