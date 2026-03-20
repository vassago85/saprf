@props(['discipline'])

@php
    $classes = match(strtoupper($discipline ?? '')) {
        'PRS' => 'bg-emerald-100 text-emerald-800 ring-emerald-600/20',
        'PR22' => 'bg-sky-100 text-sky-800 ring-sky-600/20',
        default => 'bg-stone-100 text-stone-600 ring-stone-500/20',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {$classes}"]) }}>
    {{ strtoupper($discipline ?? 'N/A') }}
</span>
