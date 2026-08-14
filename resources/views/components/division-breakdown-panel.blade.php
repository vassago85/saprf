@props([
    // One entry from ShooterStandingsSummaryService::build()['divisions'] or
    // ['provincial_divisions']. Shape: name, rank, points, pool_breakdown.
    // pool_breakdown carries the mode-specific data ('best_of_n' with
    // matches[], 'weighted_pools' with provincial/national/champs buckets,
    // or 'annual_log' with regular[] + champs).
    'division',
    // Colour theme — 'emerald' for National, 'blue' for Provincial. Kept as
    // a symbolic value rather than raw classes because Tailwind's JIT
    // needs to see the full class names for purging to work, so all
    // possible classes are spelled out below.
    'accent' => 'emerald',
])

@php
    $pb = $division['pool_breakdown'] ?? [];
    $mode = $pb['mode'] ?? null;

    $theme = match($accent) {
        'blue' => [
            'card_border' => 'border-blue-200',
            'chip_text' => 'text-blue-700',
            'value_text' => 'text-blue-800',
        ],
        default => [
            'card_border' => 'border-emerald-200',
            'chip_text' => 'text-emerald-700',
            'value_text' => 'text-emerald-800',
        ],
    };

    $matches = collect($pb['matches'] ?? []);
    $counted = $matches->where('counted', true);
    $bestOf = $pb['best_of'] ?? null;
    $max = $bestOf ? ($bestOf * 100) : null;
@endphp

<div class="rounded-xl border {{ $theme['card_border'] }} bg-white p-4">
    <div class="flex items-center justify-between mb-2">
        <span class="text-xs font-semibold {{ $theme['chip_text'] }} uppercase tracking-wider">{{ $division['name'] ?? 'Division' }}</span>
        <span class="text-xs text-stone-600 tabular-nums">
            <span class="font-bold text-amber-700">#{{ $division['rank'] ?? '—' }}</span>
            <span class="text-stone-400">&middot;</span>
            <span class="font-mono {{ $theme['value_text'] }} font-semibold">{{ number_format((float) ($division['points'] ?? 0), 2) }}</span>@if($max)<span class="text-stone-400"> / {{ $max }}</span>@endif
        </span>
    </div>

    @if($mode === 'weighted_pools' || isset($pb['provincial']) || isset($pb['national']) || isset($pb['champs']))
        {{-- PR22-style weighted pools — compact per-pool contribution row. --}}
        @php
            $poolMeta = [
                'provincial' => ['label' => 'Prov', 'colour' => 'text-sky-700'],
                'national' => ['label' => 'Nat', 'colour' => 'text-emerald-700'],
                'champs' => ['label' => 'Champs', 'colour' => 'text-amber-700'],
            ];
        @endphp
        <div class="mt-1 space-y-1 text-[11px] text-stone-500">
            @foreach($poolMeta as $key => $meta)
                @if(isset($pb[$key]))
                    @php $pool = $pb[$key]; @endphp
                    <div class="flex items-center justify-between gap-2">
                        <span class="{{ $meta['colour'] }} font-semibold uppercase tracking-wider">{{ $meta['label'] }} <span class="text-stone-400 font-normal normal-case">×{{ (int) ($pool['weight_pct'] ?? 0) }}%</span></span>
                        <span class="font-mono">
                            {{ number_format((float) ($pool['contribution'] ?? 0), 1) }}
                            <span class="text-stone-400">/ {{ (int) ($pool['weight_pct'] ?? 0) }}</span>
                            <span class="text-stone-400 ml-1">({{ $pool['scores_counted'] ?? 0 }}/{{ $pool['best_of'] ?? 0 }})</span>
                        </span>
                    </div>
                @endif
            @endforeach
        </div>

    @elseif($mode === 'annual_log')
        {{-- PRS-style annual log — best-N regular + fixed champs. --}}
        <div class="mt-1 space-y-1 text-[11px] text-stone-500">
            @foreach($pb['regular'] ?? [] as $reg)
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate">{{ $reg['match_name'] ?? ('Match #'.($reg['match_id'] ?? '?')) }}</span>
                    <span class="font-mono {{ $theme['value_text'] }} font-semibold">{{ number_format((float) ($reg['pct'] ?? 0), 2) }}</span>
                </div>
            @endforeach
            @if(!empty($pb['champs']))
                <div class="flex items-center justify-between gap-2 pt-1 border-t border-stone-100">
                    <span class="truncate text-amber-700">SA Champs &middot; {{ $pb['champs']['match_name'] ?? '?' }}</span>
                    <span class="font-mono {{ $theme['value_text'] }} font-semibold">{{ number_format((float) ($pb['champs']['pct'] ?? 0), 2) }}</span>
                </div>
            @endif
        </div>

    @else
        {{-- best_of_n (default) — simple counted-match list. --}}
        <div class="mt-1 space-y-1 text-[11px] text-stone-500">
            @forelse($counted as $m)
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate">{{ $m['match_name'] ?? ('Match #'.($m['match_id'] ?? '?')) }}</span>
                    <span class="font-mono {{ $theme['value_text'] }} font-semibold">{{ number_format((float) ($m['contribution'] ?? $m['pct'] ?? 0), 2) }}</span>
                </div>
            @empty
                <div class="text-stone-400">No counted matches in this division.</div>
            @endforelse
        </div>
    @endif
</div>
