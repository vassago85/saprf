@php
    $showProvince = $showProvince ?? true;
    $showDivision = $showDivision ?? false;
    $sort = $sort ?? null;
    $direction = $direction ?? 'desc';
    $filterParams = $filterParams ?? [];
    // Finale-eligibility map keyed by user_id. Missing key = no qualifier
    // rule for this season/series (or no OOP nationals requirement) — in
    // which case we render nothing at all so the leaderboard stays clean.
    $qualificationByUser = $qualificationByUser ?? [];

    // Default direction for each column when a user first clicks its header.
    // Points/rank are numeric; name-based columns read better ascending.
    $columnDefaults = [
        'rank' => 'asc',
        'shooter' => 'asc',
        'division' => 'asc',
        'province' => 'asc',
        'points' => 'desc',
    ];

    $sortLink = function (string $column) use ($filterParams, $sort, $direction, $columnDefaults) {
        $newDirection = $sort === $column
            ? ($direction === 'asc' ? 'desc' : 'asc')
            : ($columnDefaults[$column] ?? 'asc');

        return url('/standings') . '?' . http_build_query(array_merge(
            $filterParams,
            ['sort' => $column, 'direction' => $newDirection]
        ));
    };

    $sortArrow = function (string $column) use ($sort, $direction) {
        if ($sort !== $column) {
            return '<span class="ml-1 text-stone-300" aria-hidden="true">↕</span>';
        }
        return $direction === 'asc'
            ? '<span class="ml-1 text-emerald-700" aria-hidden="true">↑</span>'
            : '<span class="ml-1 text-emerald-700" aria-hidden="true">↓</span>';
    };

    $sortAriaLabel = function (string $column, string $columnLabel) use ($sort, $direction, $columnDefaults) {
        if ($sort !== $column) {
            $next = $columnDefaults[$column] ?? 'asc';
            return "Sort by {$columnLabel} " . ($next === 'asc' ? 'ascending' : 'descending');
        }
        $next = $direction === 'asc' ? 'descending' : 'ascending';
        return "Currently sorted by {$columnLabel} " . ($direction === 'asc' ? 'ascending' : 'descending') . " — click to switch to {$next}";
    };

    $headerBase = 'inline-flex items-center gap-0 text-[11px] font-semibold uppercase tracking-wider transition hover:text-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 rounded';
@endphp

@if($standings->isEmpty())
    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-12 text-center">
        <div class="inline-flex items-center justify-center size-16 rounded-2xl bg-stone-100 mb-4">
            <svg class="size-8 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-4.5A3.375 3.375 0 0 0 13.125 10.875h-2.25A3.375 3.375 0 0 0 7.5 14.25v4.5" /></svg>
        </div>
        <h3 class="text-lg font-semibold text-stone-700">No standings data</h3>
        <p class="mt-1 text-sm text-stone-400">No ranked shooters for this division/filter combination. Standings appear after matches are completed and scored.</p>
    </div>
@else
    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b-2 border-stone-200 bg-stone-50/50">
                        <th class="px-4 sm:px-5 py-3.5 text-center w-16">
                            <a href="{{ $sortLink('rank') }}"
                               aria-label="{{ $sortAriaLabel('rank', 'rank') }}"
                               class="{{ $headerBase }} {{ $sort === 'rank' ? 'text-stone-900' : 'text-stone-400' }}">
                                Rank {!! $sortArrow('rank') !!}
                            </a>
                        </th>
                        <th class="px-4 sm:px-5 py-3.5 text-left">
                            <a href="{{ $sortLink('shooter') }}"
                               aria-label="{{ $sortAriaLabel('shooter', 'shooter name') }}"
                               class="{{ $headerBase }} {{ $sort === 'shooter' ? 'text-stone-900' : 'text-stone-400' }}">
                                Shooter {!! $sortArrow('shooter') !!}
                            </a>
                        </th>
                        @if($showDivision)
                            <th class="px-4 sm:px-5 py-3.5 text-left hidden sm:table-cell">
                                <a href="{{ $sortLink('division') }}"
                                   aria-label="{{ $sortAriaLabel('division', 'division') }}"
                                   class="{{ $headerBase }} {{ $sort === 'division' ? 'text-stone-900' : 'text-stone-400' }}">
                                    Division {!! $sortArrow('division') !!}
                                </a>
                            </th>
                        @endif
                        @if($showProvince)
                            <th class="px-4 sm:px-5 py-3.5 text-left hidden sm:table-cell">
                                <a href="{{ $sortLink('province') }}"
                                   aria-label="{{ $sortAriaLabel('province', 'province') }}"
                                   class="{{ $headerBase }} {{ $sort === 'province' ? 'text-stone-900' : 'text-stone-400' }}">
                                    Province {!! $sortArrow('province') !!}
                                </a>
                            </th>
                        @endif
                        <th class="px-4 sm:px-5 py-3.5 text-right">
                            <a href="{{ $sortLink('points') }}"
                               aria-label="{{ $sortAriaLabel('points', 'points') }}"
                               class="{{ $headerBase }} {{ $sort === 'points' ? 'text-stone-900' : 'text-stone-400' }}">
                                Points {!! $sortArrow('points') !!}
                            </a>
                        </th>
                        <th class="px-4 sm:px-5 py-3.5 text-center w-16 hidden md:table-cell"><span class="sr-only">Details</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($standings as $standing)
                        @php
                            $userDiv = $standing->user?->division;
                        @endphp
                        <tr class="border-b border-stone-100 transition hover:bg-stone-50/50 {{ $standing->rank <= 3 ? 'bg-emerald-50/30' : '' }}">
                            <td class="px-4 sm:px-5 py-4 text-center">
                                @if($standing->rank === 1)
                                    <span class="inline-flex items-center justify-center size-8 rounded-full bg-amber-100 text-amber-700 text-sm font-bold shadow-sm">1</span>
                                @elseif($standing->rank === 2)
                                    <span class="inline-flex items-center justify-center size-8 rounded-full bg-stone-200 text-stone-600 text-sm font-bold">2</span>
                                @elseif($standing->rank === 3)
                                    <span class="inline-flex items-center justify-center size-8 rounded-full bg-amber-50 text-amber-600 text-sm font-bold">3</span>
                                @else
                                    <span class="text-sm font-medium text-stone-400">{{ $standing->rank }}</span>
                                @endif
                            </td>
                            <td class="px-4 sm:px-5 py-4">
                                @php
                                    $q = $qualificationByUser[$standing->user_id] ?? null;
                                    // Only render an indicator when the season
                                    // has a real OOP requirement (required>0).
                                    // Otherwise the flag is meaningless noise.
                                    $showQualifierFlag = $q && $q['required'] > 0;
                                @endphp
                                <span class="inline-flex items-center gap-1.5">
                                    <a href="{{ url('/standings/' . $season . '/shooter/' . ($standing->user_id ?? $standing->id)) }}"
                                       class="text-sm font-semibold text-stone-900 hover:text-emerald-700 transition">
                                        {{ $standing->user->name ?? '—' }}
                                    </a>
                                    @if($showQualifierFlag)
                                        @if($q['qualified'])
                                            <span class="inline-flex items-center justify-center size-4 rounded-full bg-emerald-100 text-emerald-700"
                                                  title="Finale-eligible — {{ $q['completed'] }} / {{ $q['required'] }} out-of-province nationals shot"
                                                  aria-label="Finale eligible">
                                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                            </span>
                                        @else
                                            <span class="inline-flex items-center justify-center size-4 rounded-full ring-1 ring-stone-300 text-stone-400"
                                                  title="Needs {{ $q['remaining'] }} more out-of-province national{{ $q['remaining'] === 1 ? '' : 's' }} to be finale-eligible ({{ $q['completed'] }} / {{ $q['required'] }})"
                                                  aria-label="Not yet finale eligible">
                                                <svg class="size-2.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2" /></svg>
                                            </span>
                                        @endif
                                    @endif
                                </span>
                                @if($showDivision)
                                    <span class="sm:hidden block text-xs text-stone-400 mt-0.5">
                                        {{ $userDiv?->name ?? '—' }}
                                    </span>
                                @endif
                                @if($showProvince)
                                    @php $prov = $standing->province ?? $standing->user?->province; @endphp
                                    <span class="sm:hidden block text-xs text-stone-400 mt-0.5">{{ $prov->abbreviation ?? $prov->name ?? '—' }}</span>
                                @endif
                            </td>
                            @if($showDivision)
                                <td class="px-4 sm:px-5 py-4 hidden sm:table-cell">
                                    <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">
                                        {{ $userDiv?->name ?? '—' }}
                                    </span>
                                </td>
                            @endif
                            @if($showProvince)
                                @php $prov = $standing->province ?? $standing->user?->province; @endphp
                                <td class="px-4 sm:px-5 py-4 hidden sm:table-cell">
                                    <span class="inline-flex items-center rounded-md bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600">
                                        {{ $prov->abbreviation ?? $prov->name ?? '—' }}
                                    </span>
                                </td>
                            @endif
                            <td class="px-4 sm:px-5 py-4 text-right">
                                <span class="text-sm font-mono font-bold text-stone-900">{{ number_format($standing->points, 1) }}</span>
                                @if(($standing->pool_breakdown['mode'] ?? null) === 'annual_log')
                                    {{-- PRS national annual log: best-N regulars + fixed champs. --}}
                                    <span class="block text-[10px] text-stone-400 mt-0.5">/ {{ $standing->pool_breakdown['max'] ?? 400 }}</span>
                                    <div class="mt-1 flex justify-end gap-1 text-[10px] font-mono">
                                        <span class="rounded bg-emerald-50 text-emerald-700 px-1 py-0.5" title="Best {{ $standing->pool_breakdown['regular_best_of'] ?? 3 }} regular (national) matches: {{ $standing->pool_breakdown['regular_counted'] ?? 0 }} counted">R {{ number_format($standing->pool_breakdown['regular_total'] ?? 0, 1) }}</span>
                                        <span class="rounded bg-amber-50 text-amber-700 px-1 py-0.5" title="SA Champs (fixed, not droppable)">C {{ number_format($standing->pool_breakdown['champs_pct'] ?? 0, 1) }}</span>
                                    </div>
                                @elseif(($standing->pool_breakdown['mode'] ?? null) === 'best_of_n')
                                    {{-- Provincial standings (PR22 provincial + legacy best-of-N): sum of best-N normalized scores. --}}
                                    @php $bo = (int) ($standing->pool_breakdown['best_of'] ?? 0); @endphp
                                    @if($bo > 0)
                                        <span class="block text-[10px] text-stone-400 mt-0.5">/ {{ $bo * 100 }}</span>
                                        <div class="mt-1 flex justify-end gap-1 text-[10px] font-mono">
                                            <span class="rounded bg-blue-50 text-blue-700 px-1 py-0.5" title="Sum of best {{ $bo }} provincial-match scores ({{ $standing->pool_breakdown['scores_counted'] ?? 0 }}/{{ $bo }} counted)">Best {{ $bo }}</span>
                                        </div>
                                    @endif
                                @elseif(isset($standing->pool_breakdown['provincial']) || isset($standing->pool_breakdown['national']) || isset($standing->pool_breakdown['champs']))
                                    {{-- PR22 national weighted pools. The "P/N/C" chips are the three
                                         match-category pools that make up the NATIONAL total on this row —
                                         NOT the separate provincial standing (that's the /standings?level=provincial view). --}}
                                    <span class="block text-[10px] text-stone-400 mt-0.5">/ 100</span>
                                    <div class="mt-1 flex justify-end gap-1 text-[10px] font-mono">
                                        @if(isset($standing->pool_breakdown['provincial']))
                                            <span class="rounded bg-sky-50 text-sky-700 px-1 py-0.5" title="Provincial-matches pool (part of the national total): best {{ $standing->pool_breakdown['provincial']['best_of'] }}, avg {{ number_format($standing->pool_breakdown['provincial']['pool_average'], 1) }}% × {{ (int) $standing->pool_breakdown['provincial']['weight_pct'] }}%">Prov {{ number_format($standing->pool_breakdown['provincial']['contribution'], 1) }}</span>
                                        @endif
                                        @if(isset($standing->pool_breakdown['national']))
                                            <span class="rounded bg-emerald-50 text-emerald-700 px-1 py-0.5" title="National-matches pool (part of the national total): best {{ $standing->pool_breakdown['national']['best_of'] }}, avg {{ number_format($standing->pool_breakdown['national']['pool_average'], 1) }}% × {{ (int) $standing->pool_breakdown['national']['weight_pct'] }}%">Nat {{ number_format($standing->pool_breakdown['national']['contribution'], 1) }}</span>
                                        @endif
                                        @if(isset($standing->pool_breakdown['champs']))
                                            <span class="rounded bg-amber-50 text-amber-700 px-1 py-0.5" title="SA Champs pool (part of the national total): best {{ $standing->pool_breakdown['champs']['best_of'] }}, avg {{ number_format($standing->pool_breakdown['champs']['pool_average'], 1) }}% × {{ (int) $standing->pool_breakdown['champs']['weight_pct'] }}%">Champs {{ number_format($standing->pool_breakdown['champs']['contribution'], 1) }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 sm:px-5 py-4 text-center hidden md:table-cell">
                                <a href="{{ url('/standings/' . $season . '/shooter/' . ($standing->user_id ?? $standing->id)) }}"
                                   class="inline-flex items-center gap-1 text-xs text-emerald-700 font-medium hover:text-emerald-800 transition">
                                    Details
                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
