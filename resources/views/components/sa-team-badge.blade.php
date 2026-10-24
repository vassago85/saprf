{{--
    SA Team representation badge.

    Props:
      years       Array of years the shooter has represented SA, e.g. [2026, 2024].
                  Empty / omitted → the component renders nothing (safe to use
                  inline without an outer @if guard).
      tooltip     Optional short detail — e.g. "Open · 2026 IPRF World Championship"
                  or "Team Manager · 2026 IPRF World Championship". Rendered as the
                  chip's title attribute and screen-reader-only group description.
      maxVisible  How many year chips to render inline before collapsing the rest
                  into a `+N` overflow pill. Default 2. Keeps scoreboard rows compact.
--}}

@props([
    'years' => [],
    'tooltip' => null,
    'maxVisible' => 2,
    // When true, render a single compact "SA ×n" pill instead of a
    // chip-per-year. Used on the public standings table (mockup pattern)
    // where real estate is tight and the shooter profile is one click
    // away for the full year list. The hover title still enumerates the
    // years so a mouse user sees the detail.
    'compact' => false,
])

@php
    $years = collect($years)
        ->filter()
        ->map(fn ($y) => (int) $y)
        ->unique()
        ->sortDesc()
        ->values();
@endphp

@if($years->isNotEmpty())
    @php
        $srLabel = 'Represented South Africa in '.$years->implode(', ');
        $titleAttr = $tooltip ?: $srLabel;
    @endphp

    @if($compact)
        <span class="inline-flex items-center gap-0.5 rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 ring-1 ring-inset ring-emerald-200 align-middle"
              role="group"
              aria-label="{{ $srLabel }}"
              title="{{ $titleAttr }}">
            <svg class="size-2.5 text-emerald-600" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/>
            </svg>
            SA ×{{ $years->count() }}
        </span>
    @else
        @php
            $visible = $years->take($maxVisible);
            $overflow = $years->count() - $visible->count();
        @endphp
        <span class="inline-flex items-center gap-1 align-middle" role="group" aria-label="{{ $srLabel }}">
            @foreach($visible as $year)
                <span class="inline-flex items-center gap-1 rounded-md bg-gradient-to-br from-emerald-50 to-amber-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 ring-1 ring-inset ring-emerald-200"
                      title="{{ $titleAttr }}">
                    <svg class="size-2.5 text-emerald-600" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/>
                    </svg>
                    SA {{ $year }}
                </span>
            @endforeach
            @if($overflow > 0)
                <span class="inline-flex items-center rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200"
                      title="Also represented SA in {{ $years->skip($maxVisible)->implode(', ') }}">
                    +{{ $overflow }}
                </span>
            @endif
        </span>
    @endif
@endif
