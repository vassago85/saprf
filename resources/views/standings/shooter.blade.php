<x-layouts.guest>
    <x-slot:title>{{ $shooter->name }} - {{ $season }} Standings - SAPRF</x-slot:title>

    <x-public-nav current="standings" />

    <div class="bg-stone-50 min-h-screen">
        {{-- Header --}}
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
                                <span class="inline-flex items-center rounded-md bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-600">
                                    {{ $shooter->province->name }}
                                </span>
                            @endif
                            <span class="text-sm text-stone-500">{{ $season }} Season</span>
                        </div>
                    </div>

                    {{-- Overall rank badges --}}
                    <div class="flex items-center gap-4">
                        @foreach($standingsSummary as $entry)
                            <div class="text-center">
                                <p class="text-xs font-semibold uppercase tracking-wider {{ $entry['series'] === 'PRS' ? 'text-emerald-600' : 'text-sky-600' }}">{{ $entry['series'] }}</p>
                                <p class="text-2xl font-bold text-stone-900">#{{ $entry['rank'] ?? '—' }}</p>
                                <p class="text-xs text-stone-400">{{ number_format($entry['points'] ?? 0, 1) }} pts</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 space-y-6">
            {{-- Season Summary Cards --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                    <p class="text-2xl font-bold text-stone-900">{{ $matchesShot }}</p>
                    <p class="text-xs text-stone-400 mt-0.5">Matches Shot</p>
                </div>
                <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                    <p class="text-2xl font-bold text-stone-900">{{ $bestPlacement ?? '—' }}</p>
                    <p class="text-xs text-stone-400 mt-0.5">Best Placement</p>
                </div>
                <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                    <p class="text-2xl font-bold text-stone-900">{{ $avgPlacement ? number_format($avgPlacement, 1) : '—' }}</p>
                    <p class="text-xs text-stone-400 mt-0.5">Avg Placement</p>
                </div>
                <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                    <p class="text-2xl font-bold text-emerald-700">{{ number_format($totalPoints, 1) }}</p>
                    <p class="text-xs text-stone-400 mt-0.5">Total Points</p>
                </div>
            </div>

            {{-- Match-by-Match Breakdown --}}
            <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-stone-100">
                    <h2 class="text-lg font-semibold text-stone-900">Match Results</h2>
                    <p class="text-xs text-stone-400 mt-0.5">All scored matches for the {{ $season }} season</p>
                </div>

                @if($scores->isEmpty())
                    <div class="p-12 text-center">
                        <p class="text-sm text-stone-400">No scored matches yet this season.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-stone-200 bg-stone-50/50">
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden sm:table-cell">Province</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden sm:table-cell">Discipline</th>
                                    <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400">#</th>
                                    <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Score</th>
                                    <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Points</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($scores as $score)
                                    @php
                                        $points = $score->placement ? max(0, 101 - $score->placement) : 0;
                                        $isTopResult = $score->placement && $score->placement <= 3;
                                    @endphp
                                    <tr class="border-b border-stone-50 hover:bg-stone-50/50 transition {{ $isTopResult ? 'bg-emerald-50/30' : '' }}">
                                        <td class="px-5 py-3 text-sm text-stone-500 whitespace-nowrap">
                                            {{ $score->match?->match_date?->format('j M') }}
                                        </td>
                                        <td class="px-5 py-3">
                                            <a href="{{ url('/events/' . $score->match_id) }}" class="text-sm font-medium text-stone-900 hover:text-emerald-700 transition">
                                                {{ $score->match?->name ?? 'Unknown' }}
                                            </a>
                                        </td>
                                        <td class="px-5 py-3 hidden sm:table-cell">
                                            <span class="inline-flex items-center rounded-md bg-stone-100 px-2 py-0.5 text-[11px] font-medium text-stone-500">
                                                {{ $score->match?->province?->abbreviation ?? '—' }}
                                            </span>
                                        </td>
                                        <td class="px-5 py-3 hidden sm:table-cell">
                                            <x-discipline-chip :discipline="$score->match?->match_type" />
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            @if($score->placement && $score->placement <= 3)
                                                @php $medal = match($score->placement) { 1 => 'bg-amber-100 text-amber-700', 2 => 'bg-stone-200 text-stone-600', 3 => 'bg-amber-50 text-amber-600', default => '' }; @endphp
                                                <span class="inline-flex items-center justify-center size-6 rounded-full {{ $medal }} text-xs font-bold">{{ $score->placement }}</span>
                                            @else
                                                <span class="text-sm text-stone-400">{{ $score->placement ?? '—' }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-right text-sm text-stone-700 tabular-nums">
                                            {{ number_format($score->raw_score, 1) }}
                                        </td>
                                        <td class="px-5 py-3 text-right text-sm font-bold text-stone-900 tabular-nums">
                                            {{ $points }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-stone-200 bg-stone-50/50">
                                <tr>
                                    <td colspan="5" class="px-5 py-3 text-sm font-semibold text-stone-700">
                                        Total ({{ $scores->count() }} {{ Str::plural('match', $scores->count()) }})
                                    </td>
                                    <td class="px-5 py-3 text-right text-sm text-stone-600 tabular-nums">
                                        {{ number_format($scores->sum('raw_score'), 1) }}
                                    </td>
                                    <td class="px-5 py-3 text-right text-sm font-bold text-emerald-700 tabular-nums">
                                        {{ $scores->sum(fn ($s) => $s->placement ? max(0, 101 - $s->placement) : 0) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Placeholder for future PRS scoring system integration --}}
            <div class="bg-stone-100 rounded-2xl border border-dashed border-stone-300 p-6 text-center">
                <p class="text-sm text-stone-500">
                    Detailed qualification breakdown, counted/dropped scores, and OOP analysis will be available once the PRS scoring system is active.
                </p>
            </div>
        </div>
    </div>

    <x-public-footer />
</x-layouts.guest>
