@props(['name', 'strokeWidth' => '1.6'])
{{-- Lucide icon from App\Support\Ui\Icon (only the Lucide set, see docs/design/README.md) --}}
{!! \App\Support\Ui\Icon::svg($name, $attributes->get('class', 'w-[18px] h-[18px]'), (string) $strokeWidth) !!}
