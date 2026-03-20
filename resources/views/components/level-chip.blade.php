@props(['level'])

@php
    $label = ucfirst($level ?? 'other');
    $classes = match(strtolower($level ?? '')) {
        'national' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'provincial' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
        'final' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
        'club' => 'bg-stone-100 text-stone-600 ring-stone-500/20',
        default => 'bg-stone-100 text-stone-600 ring-stone-500/20',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    {{ $label }}
</span>
