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
            @foreach($standingsSummary as $entry)
                <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-stone-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <h2 class="text-lg font-semibold text-stone-900">{{ $entry['series'] }} Rankings</h2>
                            <x-discipline-chip :discipline="$entry['series']" />
                        </div>
                        @if($bestOf)
                            <span class="text-xs text-stone-400">Best {{ $bestOf }} scores count</span>
                        @endif
                    </div>

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
                            @foreach($entry['categories'] as $cat)
                                <div>
                                    <p class="text-xs text-stone-400">{{ $cat['name'] }}</p>
                                    <p class="text-xl font-bold text-sky-700">#{{ $cat['rank'] ?? '—' }}</p>
                                    <p class="text-xs text-stone-500">{{ number_format($cat['points'] ?? 0, 1) }} pts</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @php
                        $seriesScores = $scores->filter(fn ($s) => $s->match?->series === $entry['series'])->values();
                        $countedScores = $bestOf ? $seriesScores->take($bestOf) : $seriesScores;
                        $droppedScores = $bestOf ? $seriesScores->slice($bestOf) : collect();
                    @endphp

                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-stone-200 bg-stone-50/50">
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden sm:table-cell">Division</th>
                                    <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400">#</th>
                                    <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Impacts</th>
                                    <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">% Score</th>
                                    <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($seriesScores as $idx => $score)
                                    @php
                                        $isCounted = !$bestOf || $idx < $bestOf;
                                    @endphp
                                    <tr class="border-b border-stone-50 {{ $isCounted ? 'hover:bg-stone-50/50' : 'opacity-50 bg-stone-50/30' }}">
                                        <td class="px-5 py-3 text-sm text-stone-500 whitespace-nowrap">{{ $score->match?->match_date?->format('j M') }}</td>
                                        <td class="px-5 py-3">
                                            <a href="{{ url('/events/' . $score->match_id) }}" class="text-sm font-medium text-stone-900 hover:text-emerald-700 transition">
                                                {{ $score->match?->name ?? 'Unknown' }}
                                            </a>
                                        </td>
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
                                        <td class="px-5 py-3 text-right text-sm font-bold {{ $isCounted ? 'text-emerald-700' : 'text-stone-400' }} tabular-nums">{{ number_format($score->normalized_score, 2) }}</td>
                                        <td class="px-5 py-3 text-center">
                                            @if($isCounted)
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200">COUNTED</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[10px] font-bold text-stone-400 ring-1 ring-inset ring-stone-200">DROPPED</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            @if($countedScores->isNotEmpty())
                                <tfoot class="border-t-2 border-stone-200 bg-stone-50/50">
                                    <tr>
                                        <td colspan="4" class="px-5 py-3 text-sm font-semibold text-stone-700">
                                            Season Total ({{ $countedScores->count() }} counted{{ $bestOf ? ' of ' . $seriesScores->count() : '' }})
                                        </td>
                                        <td class="px-5 py-3 text-right text-sm text-stone-600 tabular-nums">{{ number_format($countedScores->sum('raw_score'), 1) }}</td>
                                        <td class="px-5 py-3 text-right text-sm font-bold text-emerald-700 tabular-nums">{{ number_format($countedScores->sum('normalized_score'), 2) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            @endforeach

            @if(empty($standingsSummary))
                <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-12 text-center">
                    <h3 class="text-lg font-semibold text-stone-700">No rankings yet</h3>
                    <p class="mt-1 text-sm text-stone-400">This shooter has no scored matches in the {{ $season }} season.</p>
                </div>
            @endif

            <div class="bg-stone-100 rounded-2xl border border-dashed border-stone-300 p-4 text-center text-sm text-stone-500">
                Age-based categories are determined using the configured season classification date and remain fixed for the full season.
            </div>
        </div>
    </div>

</x-layouts.public>
