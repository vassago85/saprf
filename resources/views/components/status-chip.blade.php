@props(['status', 'closeDate' => null])

@php
    // Phase 2.2: when entries are open and the match has a registration-close
    // date in the future, upgrade the chip to the full "Entries open · Closes
    // 11 Nov" label so a shooter sees how long they have without clicking in.
    // Everything else keeps its existing short label.
    $openWithCloseDate = strtolower($status ?? '') === 'open'
        && $closeDate !== null
        && method_exists($closeDate, 'isFuture')
        && $closeDate->isFuture();

    $config = match(strtolower($status ?? '')) {
        'open' => $openWithCloseDate
            ? ['label' => 'Entries open · Closes '.$closeDate->format('j M'), 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20']
            : ['label' => 'Entries open', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
        'closed' => ['label' => 'Closed', 'class' => 'bg-stone-100 text-stone-500 ring-stone-400/20'],
        'upcoming' => ['label' => 'Not open yet', 'class' => 'bg-blue-50 text-blue-700 ring-blue-600/20'],
        'full' => ['label' => 'Full', 'class' => 'bg-red-50 text-red-700 ring-red-600/20'],
        'waitlist' => ['label' => 'Waitlist', 'class' => 'bg-amber-50 text-amber-700 ring-amber-600/20'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'bg-red-50 text-red-600 ring-red-500/20'],
        'completed' => ['label' => 'Completed', 'class' => 'bg-sky-50 text-sky-700 ring-sky-600/20'],
        'setup_incomplete' => ['label' => 'Not open yet', 'class' => 'bg-amber-50 text-amber-700 ring-amber-600/20'],
        default => ['label' => ucfirst($status ?? 'Unknown'), 'class' => 'bg-stone-100 text-stone-500 ring-stone-400/20'],
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {$config['class']}"]) }}>
    {{ $config['label'] }}
</span>
