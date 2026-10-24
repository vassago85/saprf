@props(['level', 'matchName' => null])

@php
    $label = ucfirst($level ?? 'other');
    // When the match name already ends with the chip's label — e.g. a scraped
    // "Hippo Brawl 2026 Day 1 Provincial" paired with a Provincial chip — the
    // chip becomes a verbatim repeat. Skip it so the card doesn't read
    // "Provincial Provincial".
    $nameEndsWithLabel = false;
    if ($matchName !== null && $label !== 'Other') {
        $nameEndsWithLabel = (bool) preg_match('/\b'.preg_quote($label, '/').'\s*$/i', (string) $matchName);
    }
    $classes = match(strtolower($level ?? '')) {
        'national' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'provincial' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
        'final' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
        'club' => 'bg-stone-100 text-stone-600 ring-stone-500/20',
        default => 'bg-stone-100 text-stone-600 ring-stone-500/20',
    };
@endphp

@unless($nameEndsWithLabel)
<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    {{ $label }}
</span>
@endunless
