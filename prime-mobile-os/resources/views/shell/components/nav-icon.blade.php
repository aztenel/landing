@props(['icon' => null, 'strokeWidth' => '1.6'])
{{-- Menu icon: a Lucide name (App\Support\Ui\Icon) or a Filament/heroicon name as a fallback --}}
@if ($icon && \App\Support\Ui\Icon::has($icon))
    {!! \App\Support\Ui\Icon::svg($icon, $attributes->get('class', 'h-5 w-5'), (string) $strokeWidth) !!}
@elseif ($icon)
    <x-filament::icon :icon="$icon" {{ $attributes->merge(['class' => 'h-5 w-5']) }} />
@endif
