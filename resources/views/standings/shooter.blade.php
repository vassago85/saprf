<x-layouts.public :title="$shooter->name . ' - ' . $season . ' Rankings - SAPRF'" current="standings">
    <div class="bg-stone-50 min-h-screen">
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">
                <a href="{{ url('/standings?season=' . $season) }}"
                   class="inline-flex items-center gap-1.5 text-sm text-stone-500 hover:text-emerald-700 transition mb-5">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    Back to Standings
                </a>

                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div>
                        <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $shooter->name }}</h1>
                        <div class="flex items-center gap-3 mt-2">
                            @if($shooter->province)
                                <span class="inline-flex items-center rounded-md bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-600">{{ $shooter->province->name }}</span>
                            @endif
                            <span class="text-sm text-stone-500">{{ $season }} Season</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-6 flex-wrap">
                        @foreach($standingsSummary as $entry)
                            {{-- National standing badge (both PRS and PR22 have one). --}}
                            @if($entry['overall_rank'] !== null)
                                <div class="text-center">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider {{ $entry['series'] === 'PRS' ? 'text-emerald-600' : 'text-sky-600' }}">{{ $entry['series'] }} National</p>
                                    <p class="text-2xl font-bold text-stone-900">#{{ $entry['overall_rank'] }}</p>
                                    <p class="text-xs text-stone-400">{{ number_format($entry['overall_points'] ?? 0, 1) }} pts</p>
                                </div>
                            @endif

                            {{-- Provincial standing badge (PR22 only). Kept as a
                                 separate, differently-coloured tile so there is
                                 no possibility of reading a provincial rank as
                                 a national one. --}}
                            @if(!empty($entry['has_provincial']))
                                <div class="text-center border-l border-stone-200 pl-6">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-600">
                                        {{ $entry['series'] }} Provincial
                                        @if($entry['province_name'])
                                            <span class="text-blue-400">&middot; {{ $entry['province_name'] }}</span>
                                        @endif
                                    </p>
                                    <p class="text-2xl font-bold text-stone-900">#{{ $entry['provincial_rank'] ?? '—' }}</p>
                                    <p class="text-xs text-stone-400">{{ number_format($entry['provincial_points'] ?? 0, 1) }} pts</p>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 space-y-6">
            @php
                $statusBadges = [
                    'valid' => ['label' => 'Counts', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
                    'pending' => ['label' => 'Pending', 'class' => 'bg-amber-50 text-amber-700 ring-amber-200'],
                    'lapsed' => ['label' => 'Lapsed', 'class' => 'bg-orange-50 text-orange-700 ring-orange-200'],
                    'non_member' => ['label' => 'Non-member', 'class' => 'bg-stone-100 text-stone-500 ring-stone-200'],
                ];
            @endphp
            @foreach($seriesOrder as $series)
                @php
                    $entry = $summaryBySeries[$series] ?? null;
                    $seriesScores = ($scoresBySeries[$series] ?? collect())->values();
                @endphp
                <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-stone-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <h2 class="text-lg font-semibold text-stone-900">{{ $series }} @if($entry) Rankings @else Matches @endif</h2>
                            <x-discipline-chip :discipline="$series" />
                        </div>
                        @if($entry && ($entry['scoring_mode'] ?? null) === 'weighted_pools')
                            <span class="text-xs text-stone-400">Weighted pool total (out of 100)</span>
                        @elseif($entry && ($entry['pool_breakdown']['mode'] ?? null) === 'annual_log')
                            <span class="text-xs text-stone-400">Best 3 nationals + SA Champs (out of 400)</span>
                        @elseif($entry && $bestOf)
                            <span class="text-xs text-stone-400">Best {{ $bestOf }} scores count</span>
                        @elseif(! $entry)
                            <span class="text-xs text-stone-400">{{ $seriesScores->count() }} match{{ $seriesScores->count() === 1 ? '' : 'es' }} attended — not ranked</span>
                        @endif
                    </div>

                    @if($entry)

                    {{-- Annual "national log" breakdown card (best-N regular + fixed champs, e.g. PRS) --}}
                    @if(($entry['pool_breakdown']['mode'] ?? null) === 'annual_log')
                        @php $pb = $entry['pool_breakdown']; @endphp
                        <div class="px-6 py-5 border-b border-stone-100 bg-gradient-to-br from-stone-50 to-white">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-stone-500 mb-3">Annual Log Breakdown</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4">
                                    <span class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">Best {{ $pb['regular_best_of'] ?? 3 }} Regular</span>
                                    <div class="mt-2 flex items-baseline gap-1">
                                        <span class="text-2xl font-bold text-emerald-800 tabular-nums">{{ number_format($pb['regular_total'] ?? 0, 2) }}</span>
                                        <span class="text-xs text-stone-400">/ {{ ($pb['regular_best_of'] ?? 3) * 100 }}</span>
                                    </div>
                                    <div class="mt-2 space-y-1 text-[11px] text-stone-500">
                                        @forelse($pb['regular'] ?? [] as $reg)
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="truncate">{{ $reg['match_name'] ?? ('Match #'.$reg['match_id']) }}</span>
                                                <span class="font-mono">{{ number_format($reg['pct'], 2) }}%</span>
                                            </div>
                                        @empty
                                            <div class="text-stone-400">No regular matches counted.</div>
                                        @endforelse
                                    </div>
                                </div>
                                <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-4">
                                    <span class="text-xs font-semibold text-amber-700 uppercase tracking-wider">SA Champs</span>
                                    <div class="mt-2 flex items-baseline gap-1">
                                        <span class="text-2xl font-bold text-amber-800 tabular-nums">{{ number_format($pb['champs_pct'] ?? 0, 2) }}</span>
                                        <span class="text-xs text-stone-400">/ 100</span>
                                    </div>
                                    <div class="mt-2 text-[11px] text-stone-500">
                                        @if(!empty($pb['champs']))
                                            <span class="truncate">{{ $pb['champs']['match_name'] ?? ('Match #'.$pb['champs']['match_id']) }}</span>
                                        @else
                                            <span class="text-stone-400">Champs not shot — fixed at 0 (cannot be replaced by a regular match).</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="rounded-lg bg-stone-900 text-white px-4 py-3 flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider">Annual Total</span>
                                <span class="text-2xl font-bold tabular-nums">{{ number_format($pb['total'] ?? ($entry['overall_points'] ?? 0), 2) }} / {{ $pb['max'] ?? 400 }}</span>
                            </div>
                        </div>
                    {{-- Pool breakdown card (weighted-pools mode, e.g. PR22 NATIONAL standing) --}}
                    @elseif(!empty($entry['pool_breakdown']))
                        @php
                            $pb = $entry['pool_breakdown'];
                            // Each pool is one of three "buckets" of matches
                            // inside the NATIONAL standing. The labels are
                            // spelled out so the reader can never mistake the
                            // "provincial-matches pool" (inside national) for
                            // the separate provincial standing block below.
                            $poolMeta = [
                                'provincial' => [
                                    'label' => 'Provincial matches',
                                    'card' => 'border-sky-200 bg-sky-50/50',
                                    'chip' => 'text-sky-700',
                                    'value' => 'text-sky-800',
                                ],
                                'national' => [
                                    'label' => 'National matches',
                                    'card' => 'border-emerald-200 bg-emerald-50/50',
                                    'chip' => 'text-emerald-700',
                                    'value' => 'text-emerald-800',
                                ],
                                'champs' => [
                                    'label' => 'SA Champs',
                                    'card' => 'border-amber-200 bg-amber-50/50',
                                    'chip' => 'text-amber-700',
                                    'value' => 'text-amber-800',
                                ],
                            ];
                        @endphp
                        <div class="px-6 py-5 border-b border-stone-100 bg-gradient-to-br from-stone-50 to-white">
                            <div class="flex items-baseline justify-between mb-3">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-emerald-700">National Standing Breakdown</h3>
                                <span class="text-[10px] text-stone-400">Weighted average of your {{ $series }} matches (out of 100)</span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                                @foreach($poolMeta as $key => $meta)
                                    @if(isset($pb[$key]))
                                        @php $pool = $pb[$key]; @endphp
                                        <div class="rounded-xl border {{ $meta['card'] }} p-4">
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-semibold {{ $meta['chip'] }} uppercase tracking-wider">{{ $meta['label'] }}</span>
                                                <span class="text-[10px] font-mono text-stone-500">×{{ (int) $pool['weight_pct'] }}%</span>
                                            </div>
                                            <div class="mt-2 flex items-baseline gap-1">
                                                <span class="text-2xl font-bold {{ $meta['value'] }} tabular-nums">{{ number_format($pool['contribution'], 1) }}</span>
                                                <span class="text-xs text-stone-400">/ {{ (int) $pool['weight_pct'] }}</span>
                                            </div>
                                            <div class="mt-1 text-[11px] text-stone-500">
                                                Best {{ $pool['best_of'] }} avg: <span class="font-mono">{{ number_format($pool['pool_average'], 1) }}%</span>
                                                <span class="text-stone-400">({{ $pool['scores_counted'] }}/{{ $pool['best_of'] }} counted)</span>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                            <div class="rounded-lg bg-stone-900 text-white px-4 py-3 flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider">National Season Total</span>
                                <span class="text-2xl font-bold tabular-nums">{{ number_format($entry['overall_points'] ?? 0, 1) }} / 100</span>
                            </div>
                        </div>

                        {{-- Provincial standing (PR22 only). Rendered as its
                             own visually distinct block so nothing here can be
                             read as part of the national standing above. --}}
                        @if(!empty($entry['has_provincial']))
                            @php
                                $ppb = $entry['provincial_pool_breakdown'] ?? [];
                                $ppMatches = collect($ppb['matches'] ?? []);
                                $ppCounted = $ppMatches->where('counted', true);
                                $ppBestOf = $ppb['best_of'] ?? null;
                                $ppMax = $ppBestOf ? ($ppBestOf * 100) : null;
                            @endphp
                            <div class="px-6 py-5 border-b border-stone-100 bg-gradient-to-br from-blue-50/40 to-white">
                                <div class="flex items-baseline justify-between mb-3">
                                    <h3 class="text-xs font-semibold uppercase tracking-wider text-blue-700">
                                        Provincial Standing Breakdown
                                        @if($entry['province_name'])
                                            <span class="text-blue-400 font-normal normal-case">&middot; {{ $entry['province_name'] }}</span>
                                        @endif
                                    </h3>
                                    <span class="text-[10px] text-stone-400">
                                        @if($ppBestOf)
                                            Sum of your best {{ $ppBestOf }} provincial-match scores
                                        @else
                                            Sum of your qualifying provincial-match scores
                                        @endif
                                    </span>
                                </div>
                                <div class="rounded-xl border border-blue-200 bg-white/60 p-4 mb-3">
                                    <div class="text-[11px] text-stone-500 space-y-1">
                                        @forelse($ppCounted as $m)
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="truncate">{{ $m['match_name'] ?? ('Match #'.$m['match_id']) }}</span>
                                                <span class="font-mono text-blue-800 font-semibold">{{ number_format((float) $m['contribution'], 2) }}</span>
                                            </div>
                                        @empty
                                            <div class="text-stone-400">No provincial matches counted yet.</div>
                                        @endforelse
                                    </div>
                                </div>
                                <div class="rounded-lg bg-blue-900 text-white px-4 py-3 flex items-center justify-between">
                                    <span class="text-xs font-semibold uppercase tracking-wider">Provincial Season Total</span>
                                    <span class="text-2xl font-bold tabular-nums">
                                        {{ number_format((float) ($entry['provincial_points'] ?? 0), 1) }}@if($ppMax)<span class="text-xs text-blue-200"> / {{ $ppMax }}</span>@endif
                                    </span>
                                </div>
                            </div>
                        @endif
                    @endif

                    <div class="px-6 py-4 border-b border-stone-50 bg-stone-50/30">
                        <div class="grid grid-cols-1 @if(!empty($entry['has_provincial'])) md:grid-cols-2 @endif gap-4">
                            {{-- National standing rank/points --}}
                            @if($entry['overall_rank'] !== null)
                                <div class="text-center @if(!empty($entry['has_provincial'])) md:border-r md:border-stone-200 @endif">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700 mb-1">{{ $series }} National</p>
                                    <div class="flex items-start justify-center gap-6">
                                        <div>
                                            <p class="text-xs text-stone-400">Overall</p>
                                            <p class="text-xl font-bold text-stone-900">#{{ $entry['overall_rank'] }}</p>
                                            <p class="text-xs text-stone-500">{{ number_format($entry['overall_points'] ?? 0, 1) }} pts</p>
                                        </div>
                                        @if($entry['division_name'])
                                            <div>
                                                <p class="text-xs text-stone-400">{{ $entry['division_name'] }}</p>
                                                <p class="text-xl font-bold text-amber-700">#{{ $entry['division_rank'] ?? '—' }}</p>
                                                <p class="text-xs text-stone-500">{{ number_format($entry['division_points'] ?? 0, 1) }} pts</p>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            {{-- Provincial standing rank/points (PR22 only) --}}
                            @if(!empty($entry['has_provincial']))
                                <div class="text-center">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-700 mb-1">
                                        {{ $series }} Provincial
                                        @if($entry['province_name'])
                                            <span class="text-blue-400">&middot; {{ $entry['province_name'] }}</span>
                                        @endif
                                    </p>
                                    <div class="flex items-start justify-center gap-6">
                                        <div>
                                            <p class="text-xs text-stone-400">Overall</p>
                                            <p class="text-xl font-bold text-stone-900">#{{ $entry['provincial_rank'] ?? '—' }}</p>
                                            <p class="text-xs text-stone-500">{{ number_format($entry['provincial_points'] ?? 0, 1) }} pts</p>
                                        </div>
                                        @if($entry['provincial_division_name'])
                                            <div>
                                                <p class="text-xs text-stone-400">{{ $entry['provincial_division_name'] }}</p>
                                                <p class="text-xl font-bold text-amber-700">#{{ $entry['provincial_division_rank'] ?? '—' }}</p>
                                                <p class="text-xs text-stone-500">{{ number_format($entry['provincial_division_points'] ?? 0, 1) }} pts</p>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                    @endif

                    @php
                        // PR22 gets two contribution columns (National + Provincial);
                        // PRS only has a national standing so one column.
                        $hasProvincialCol = $series === 'PR22';
                        // Column count for empty/tfoot rows: 7 base cols
                        // (Date, Match, Level, Division, #, Impacts, %Score)
                        // + Status + 1 contribution col (PRS) or 2 (PR22).
                        $tableColCount = $hasProvincialCol ? 10 : 9;
                    @endphp
                    <div class="px-6 pt-4 pb-1">
                        <p class="text-xs text-stone-400">
                            All <span class="font-semibold text-stone-600">{{ $series }}</span> matches this shooter attended in {{ $season }}.
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-stone-200 bg-stone-50/50">
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden md:table-cell">Level</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden sm:table-cell">Division</th>
                                    <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400">#</th>
                                    <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Impacts</th>
                                    <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">% Score</th>
                                    <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-emerald-700 bg-emerald-50/40" title="Points this match contributed toward the {{ $series }} National standing">Nat. Pts</th>
                                    @if($hasProvincialCol)
                                        <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-blue-700 bg-blue-50/40" title="Points this match contributed toward the {{ $series }} Provincial standing">Prov. Pts</th>
                                    @endif
                                    <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($seriesScores as $score)
                                    @php
                                        $badge = $statusBadges[$score->status] ?? $statusBadges['non_member'];
                                        $level = $score->match?->series_level;
                                        $levelLabel = match($level) {
                                            'national' => 'National',
                                            'provincial' => 'Provincial',
                                            'final' => 'SA Champs',
                                            default => ucfirst((string) $level),
                                        };
                                        $contribution = $contributionByMatch[$score->match_id] ?? null;
                                        $countedNat = (bool) ($contribution['counted_national'] ?? false);
                                        $countedProv = (bool) ($contribution['counted_provincial'] ?? false);
                                        $isValid = $score->status === 'valid';

                                        // Only provincial-level matches feed the
                                        // provincial standing. A national match
                                        // (even a 2-day national) stays national
                                        // — if day-1 should count provincially,
                                        // the MD posts it as its own separate
                                        // provincial match.
                                        $feedsProvincial = $level === 'provincial';
                                    @endphp
                                    <tr class="border-b border-stone-50 hover:bg-stone-50/50">
                                        <td class="px-5 py-3 text-sm text-stone-500 whitespace-nowrap">{{ $score->match?->match_date?->format('j M') }}</td>
                                        <td class="px-5 py-3">
                                            <a href="{{ url('/events/' . $score->match_id) }}" class="text-sm font-medium text-stone-900 hover:text-emerald-700 transition">
                                                {{ $score->match?->name ?? 'Unknown' }}
                                            </a>
                                            <span class="md:hidden block text-[11px] text-stone-400">{{ $levelLabel }}</span>
                                        </td>
                                        <td class="px-5 py-3 hidden md:table-cell text-sm text-stone-500">{{ $levelLabel }}</td>
                                        <td class="px-5 py-3 hidden sm:table-cell text-sm text-stone-500">{{ $score->division?->name ?? '—' }}</td>
                                        <td class="px-5 py-3 text-center">
                                            @if($score->overall_rank && $score->overall_rank <= 3)
                                                @php $medal = match($score->overall_rank) { 1 => 'bg-amber-100 text-amber-700', 2 => 'bg-stone-200 text-stone-600', 3 => 'bg-amber-50 text-amber-600', default => '' }; @endphp
                                                <span class="inline-flex items-center justify-center size-6 rounded-full {{ $medal }} text-xs font-bold">{{ $score->overall_rank }}</span>
                                            @else
                                                <span class="text-sm text-stone-400">{{ $score->overall_rank ?? '—' }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-right text-sm text-stone-700 tabular-nums">{{ number_format($score->raw_score, 1) }}</td>
                                        <td class="px-5 py-3 text-right text-sm font-bold text-stone-700 tabular-nums">{{ number_format($score->normalized_score, 2) }}</td>

                                        {{-- National standing contribution --}}
                                        <td class="px-5 py-3 text-center whitespace-nowrap bg-emerald-50/20">
                                            @if($countedNat)
                                                <span class="text-sm font-bold text-emerald-700 tabular-nums" title="Points contributed to {{ $series }} National standing">
                                                    +{{ number_format((float) $contribution['national_pts'], 2) }}
                                                </span>
                                            @elseif($isValid)
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset bg-stone-100 text-stone-400 ring-stone-200" title="Valid score, but not among the counting matches for the National standing">DROPPED</span>
                                            @else
                                                <span class="text-stone-300">—</span>
                                            @endif
                                        </td>

                                        {{-- Provincial standing contribution (PR22 only) --}}
                                        @if($hasProvincialCol)
                                            <td class="px-5 py-3 text-center whitespace-nowrap bg-blue-50/20">
                                                @if(!$feedsProvincial)
                                                    <span class="text-stone-300" title="This match does not feed the Provincial standing">—</span>
                                                @elseif($countedProv)
                                                    <span class="text-sm font-bold text-blue-700 tabular-nums" title="Points contributed to {{ $series }} Provincial standing">
                                                        +{{ number_format((float) $contribution['provincial_pts'], 2) }}
                                                    </span>
                                                @elseif($isValid)
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset bg-stone-100 text-stone-400 ring-stone-200" title="Valid score, but not among the counting matches for the Provincial standing">DROPPED</span>
                                                @else
                                                    <span class="text-stone-300">—</span>
                                                @endif
                                            </td>
                                        @endif

                                        <td class="px-5 py-3 text-center">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset {{ $badge['class'] }}">{{ strtoupper($badge['label']) }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $tableColCount }}" class="px-5 py-8 text-center text-sm text-stone-400">No matches attended in this series.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if($seriesScores->isNotEmpty())
                                <tfoot class="border-t-2 border-stone-200 bg-stone-50/50">
                                    <tr>
                                        <td colspan="6" class="px-5 py-3 text-sm font-semibold text-stone-700">
                                            {{ $seriesScores->count() }} match{{ $seriesScores->count() === 1 ? '' : 'es' }} attended
                                        </td>
                                        <td class="px-5 py-3 text-right text-sm font-bold text-stone-600 tabular-nums" title="Best % score">{{ number_format($seriesScores->max('normalized_score'), 2) }}</td>
                                        <td class="px-5 py-3 text-center text-sm font-bold text-emerald-700 tabular-nums bg-emerald-50/20" title="Total points contributed to {{ $series }} National standing">
                                            {{ number_format(collect($seriesScores)->sum(fn($s) => (float) ($contributionByMatch[$s->match_id]['national_pts'] ?? 0)), 2) }}
                                        </td>
                                        @if($hasProvincialCol)
                                            <td class="px-5 py-3 text-center text-sm font-bold text-blue-700 tabular-nums bg-blue-50/20" title="Total points contributed to {{ $series }} Provincial standing">
                                                {{ number_format(collect($seriesScores)->sum(fn($s) => (float) ($contributionByMatch[$s->match_id]['provincial_pts'] ?? 0)), 2) }}
                                            </td>
                                        @endif
                                        <td></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            @endforeach

            @if($seriesOrder->isEmpty())
                <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-12 text-center">
                    <h3 class="text-lg font-semibold text-stone-700">No matches yet</h3>
                    <p class="mt-1 text-sm text-stone-400">This shooter has no recorded matches in the {{ $season }} season.</p>
                </div>
            @endif

        </div>
    </div>

</x-layouts.public>
