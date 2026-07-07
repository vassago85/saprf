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

                    <div class="flex items-center gap-6">
                        @foreach($standingsSummary as $entry)
                            <div class="text-center">
                                <p class="text-xs font-semibold uppercase tracking-wider {{ $entry['series'] === 'PRS' ? 'text-emerald-600' : 'text-sky-600' }}">{{ $entry['series'] }}</p>
                                <p class="text-2xl font-bold text-stone-900">#{{ $entry['overall_rank'] ?? '—' }}</p>
                                <p class="text-xs text-stone-400">{{ number_format($entry['overall_points'] ?? 0, 1) }} pts</p>
                            </div>
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
                    {{-- Pool breakdown card (weighted-pools mode, e.g. PR22) --}}
                    @elseif(!empty($entry['pool_breakdown']))
                        @php
                            $pb = $entry['pool_breakdown'];
                            $poolMeta = [
                                'provincial' => [
                                    'label' => 'Provincial',
                                    'card' => 'border-blue-200 bg-blue-50/50',
                                    'chip' => 'text-blue-700',
                                    'value' => 'text-blue-800',
                                ],
                                'national' => [
                                    'label' => 'National',
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
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-stone-500 mb-3">Season Score Breakdown</h3>
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
                                <span class="text-xs font-semibold uppercase tracking-wider">Season Total</span>
                                <span class="text-2xl font-bold tabular-nums">{{ number_format($entry['overall_points'] ?? 0, 1) }} / 100</span>
                            </div>
                        </div>
                    @endif

                    <div class="px-6 py-4 border-b border-stone-50 bg-stone-50/30">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                            <div>
                                <p class="text-xs text-stone-400">Overall</p>
                                <p class="text-xl font-bold text-stone-900">#{{ $entry['overall_rank'] ?? '—' }}</p>
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
                                    <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($seriesScores as $score)
                                    @php
                                        $badge = $statusBadges[$score->status] ?? $statusBadges['non_member'];
                                        $levelLabel = match($score->match?->series_level) {
                                            'national' => 'National',
                                            'provincial' => 'Provincial',
                                            'final' => 'SA Champs',
                                            default => ucfirst((string) $score->match?->series_level),
                                        };
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
                                        <td class="px-5 py-3 text-center">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset {{ $badge['class'] }}">{{ strtoupper($badge['label']) }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-5 py-8 text-center text-sm text-stone-400">No matches attended in this series.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if($seriesScores->isNotEmpty())
                                <tfoot class="border-t-2 border-stone-200 bg-stone-50/50">
                                    <tr>
                                        <td colspan="6" class="px-5 py-3 text-sm font-semibold text-stone-700">
                                            {{ $seriesScores->count() }} match{{ $seriesScores->count() === 1 ? '' : 'es' }} attended
                                        </td>
                                        <td class="px-5 py-3 text-right text-sm font-bold text-emerald-700 tabular-nums" title="Best % score">{{ number_format($seriesScores->max('normalized_score'), 2) }}</td>
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
