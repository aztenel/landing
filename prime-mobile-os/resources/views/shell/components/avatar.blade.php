@props(['name' => ''])
@php($initials = collect(preg_split('/\s+/u', trim((string) $name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode(''))
<span {{ $attributes->merge(['class' => 'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary']) }}>{{ $initials ?: '•' }}</span>
