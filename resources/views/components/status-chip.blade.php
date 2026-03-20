@props(['status'])

@php
    $config = match(strtolower($status ?? '')) {
        'open' => ['label' => 'Open', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
        'closed' => ['label' => 'Closed', 'class' => 'bg-stone-100 text-stone-500 ring-stone-400/20'],
        'upcoming' => ['label' => 'Opens Soon', 'class' => 'bg-blue-50 text-blue-700 ring-blue-600/20'],
        'full' => ['label' => 'Full', 'class' => 'bg-red-50 text-red-700 ring-red-600/20'],
        'waitlist' => ['label' => 'Waitlist', 'class' => 'bg-amber-50 text-amber-700 ring-amber-600/20'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'bg-red-50 text-red-600 ring-red-500/20'],
        'completed' => ['label' => 'Completed', 'class' => 'bg-sky-50 text-sky-700 ring-sky-600/20'],
        default => ['label' => ucfirst($status ?? 'Unknown'), 'class' => 'bg-stone-100 text-stone-500 ring-stone-400/20'],
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {$config['class']}"]) }}>
    {{ $config['label'] }}
</span>
