<x-layouts.app :title="'Participation Report - SAPRF'">
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <a href="{{ route('reports.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; All Reports</a>
                <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Participation Report</h1>
                <p class="mt-1 text-sm text-stone-500">Match-by-match entries, scores, and shooter participation across the season.</p>
            </div>
            <a href="{{ route('reports.participation.export', request()->only(['series', 'season'])) }}"
               class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                Export CSV
            </a>
        </div>

        {{-- Filters --}}
        <form method="GET" class="rounded-xl border border-stone-200 bg-white shadow-sm p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Season</label>
                    <select name="season" onchange="this.form.submit()"
                            class="rounded-lg border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        @foreach($seasons as $s)
                            <option value="{{ $s }}" @selected((string) $season === $s)>{{ $s }} Season</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Series</label>
                    <select name="series" onchange="this.form.submit()"
                            class="rounded-lg border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="" @selected(!$series)>All series</option>
                        <option value="PRS" @selected($series === 'PRS')>PRS</option>
                        <option value="PR22" @selected($series === 'PR22')>PR22</option>
                    </select>
                </div>
            </div>
        </form>

        {{-- KPIs --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Matches</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">{{ $totalMatches }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                <p class="text-xs font-medium text-emerald-700 uppercase">Confirmed Entries</p>
                <p class="mt-1 text-2xl font-bold text-emerald-900">{{ number_format($totalEntries) }}</p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                <p class="text-xs font-medium text-amber-700 uppercase">Waitlisted</p>
                <p class="mt-1 text-2xl font-bold text-amber-900">{{ number_format($totalWaitlisted) }}</p>
            </div>
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-5">
                <p class="text-xs font-medium text-sky-700 uppercase">Valid Scores</p>
                <p class="mt-1 text-2xl font-bold text-sky-900">{{ number_format($totalScores) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Unique Shooters</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">{{ number_format($uniqueShooters) }}</p>
            </div>
        </div>

        {{-- Province Breakdown --}}
        @if($byProvince->isNotEmpty())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
                <div class="px-5 py-4 border-b border-stone-100">
                    <h2 class="font-semibold text-stone-900">Participation by Province</h2>
                    <p class="text-xs text-stone-500 mt-0.5">Unique shooters and total scores per province for this season.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-stone-50 border-b border-stone-200">
                            <tr class="text-left text-xs uppercase text-stone-500">
                                <th class="px-5 py-3">Province</th>
                                <th class="px-5 py-3 text-right">Unique Shooters</th>
                                <th class="px-5 py-3 text-right">Total Scores</th>
                                <th class="px-5 py-3 text-right">Avg Scores / Shooter</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($byProvince as $row)
                                <tr class="hover:bg-stone-50">
                                    <td class="px-5 py-3 font-medium text-stone-900">{{ $row->province_name }}</td>
                                    <td class="px-5 py-3 text-right font-mono text-stone-900">{{ $row->shooter_count }}</td>
                                    <td class="px-5 py-3 text-right font-mono text-stone-900">{{ $row->score_count }}</td>
                                    <td class="px-5 py-3 text-right font-mono text-stone-500">
                                        {{ $row->shooter_count > 0 ? number_format($row->score_count / $row->shooter_count, 1) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Match Table --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="px-5 py-4 border-b border-stone-100">
                <h2 class="font-semibold text-stone-900">Matches in {{ $season }}{{ $series ? ' &middot; ' . $series : '' }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 border-b border-stone-200">
                        <tr class="text-left text-xs uppercase text-stone-500">
                            <th class="px-5 py-3">Date</th>
                            <th class="px-5 py-3">Match</th>
                            <th class="px-5 py-3">Type</th>
                            <th class="px-5 py-3">Level</th>
                            <th class="px-5 py-3">Province</th>
                            <th class="px-5 py-3 text-right">Confirmed</th>
                            <th class="px-5 py-3 text-right">Waitlist</th>
                            <th class="px-5 py-3 text-right">Scores</th>
                            <th class="px-5 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse($matches as $match)
                            <tr class="hover:bg-stone-50">
                                <td class="px-5 py-3 text-stone-500 whitespace-nowrap">{{ $match->match_date->format('d M Y') }}</td>
                                <td class="px-5 py-3 font-medium text-stone-900">
                                    <a href="{{ route('matches.show', $match) }}" class="hover:text-emerald-700">{{ $match->name }}</a>
                                </td>
                                <td class="px-5 py-3">
                                    @if($match->match_type === 'PRS')
                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">PRS</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-700">PR22</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-stone-600 capitalize">{{ $match->series_level }}</td>
                                <td class="px-5 py-3 text-stone-600">{{ $match->province?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-right font-mono text-stone-900">
                                    {{ $match->confirmed_registrations }}
                                    @if($match->max_competitors)
                                        <span class="text-xs text-stone-400">/{{ $match->max_competitors }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right font-mono {{ $match->waitlisted_registrations > 0 ? 'text-amber-700 font-semibold' : 'text-stone-400' }}">{{ $match->waitlisted_registrations }}</td>
                                <td class="px-5 py-3 text-right font-mono text-stone-900">{{ $match->valid_scores }}</td>
                                <td class="px-5 py-3">
                                    @php
                                        $statusColor = match($match->status) {
                                            'completed' => 'bg-emerald-50 text-emerald-700',
                                            'open' => 'bg-sky-50 text-sky-700',
                                            'closed' => 'bg-stone-100 text-stone-600',
                                            'cancelled' => 'bg-red-50 text-red-700',
                                            default => 'bg-stone-100 text-stone-500',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize {{ $statusColor }}">{{ $match->status }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-12 text-center text-sm text-stone-400">No matches found for this season.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
