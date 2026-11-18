<x-layouts.app :title="'Federation Colours - SAPRF'">
    @php
        $query = array_filter([
            'series' => $series,
            'season' => $season,
            'division' => $divisionId,
        ], fn ($value) => $value !== null && $value !== '');
        $seriesQuery = fn (string $next) => array_merge($query, ['series' => $next]);
        $counts = ['listed' => 0, 'Gold' => 0, 'Silver' => 0, 'Bronze' => 0];
        foreach ($report['divisions'] as $division) {
            foreach ($division['rows'] as $row) {
                $counts['listed']++;
                if ($row['award'] !== null) {
                    $counts[$row['award']]++;
                }
            }
        }
    @endphp
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
            <div>
                <a href="{{ route('reports.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; All Reports</a>
                <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Federation Colours</h1>
                <p class="mt-1 text-sm text-stone-500 max-w-2xl">
                    National awards for {{ $seriesLabel }} {{ $season }}, one list per division.
                    Each percentage is that shooter's series total divided by the highest series total in the same division.
                    Gold is 95% and above, silver is 90% up to 95%, and bronze is 85% up to 90%.
                    Every paid-up member with a {{ $seriesLabel }} log appears, so the leaderboard is a live snapshot all season.
                    A colour is awarded only once the shooter has also shot a qualifying match — either the one-day national that counts as the provincial in their home province, or the SA Championships in that division (the completed championship or, while it is still running, day 1 of it; home province is irrelevant for the champs route). Shooters sitting in a colour band without a qualifying match show a dash in the Award column until they qualify.
                </p>
            </div>
            <a href="{{ route('reports.federation-colour-awards.csv', $query) }}"
               class="inline-flex items-center gap-2 rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-semibold text-stone-800 shadow-sm hover:bg-stone-50 transition">
                Download CSV
            </a>
        </div>

        <form method="GET" class="rounded-xl border border-stone-200 bg-white shadow-sm p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Series</label>
                    <div class="flex rounded-lg bg-stone-50 border border-stone-200 p-1 w-fit">
                        <a href="{{ route('reports.federation-colour-awards', $seriesQuery('PRS')) }}"
                           class="px-4 py-1.5 rounded text-sm font-semibold transition {{ $series === 'PRS' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:bg-stone-100' }}">Centrefire</a>
                        <a href="{{ route('reports.federation-colour-awards', $seriesQuery('PR22')) }}"
                           class="px-4 py-1.5 rounded text-sm font-semibold transition {{ $series === 'PR22' ? 'bg-sky-600 text-white shadow-sm' : 'text-stone-600 hover:bg-stone-100' }}">PR22</a>
                    </div>
                </div>
                <div>
                    <label for="season" class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Season</label>
                    <select id="season" name="season"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        @foreach ($seasons as $s)
                            <option value="{{ $s }}" @selected((string) $season === (string) $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="division" class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Division</label>
                    <select id="division" name="division"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All divisions</option>
                        @foreach ($catalog as $division)
                            <option value="{{ $division['id'] }}" @selected((int) $divisionId === (int) $division['id'])>{{ $division['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <input type="hidden" name="series" value="{{ $series }}">
                <button type="submit"
                        class="inline-flex items-center rounded-xl bg-stone-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-stone-800 transition">
                    Apply
                </button>
            </div>
        </form>

        @if ($counts['listed'] > 0)
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach (['listed' => 'Listed', 'Gold' => 'Gold', 'Silver' => 'Silver', 'Bronze' => 'Bronze'] as $key => $label)
                    <div class="rounded-xl border border-stone-200 bg-white px-4 py-3 shadow-sm">
                        <div class="text-xs font-semibold uppercase text-stone-500">{{ $label }}</div>
                        <div class="mt-1 text-2xl font-bold text-stone-900">{{ $counts[$key] }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        @forelse ($report['divisions'] as $division)
            <section class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-stone-100 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-heading text-lg font-bold text-stone-900">{{ $division['name'] }}</h2>
                    <p class="text-xs text-stone-500">Reference score {{ number_format($division['reference_score'], 2) }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-stone-50 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-4 py-2">Rank</th>
                                <th class="px-4 py-2">Shooter</th>
                                <th class="px-4 py-2">Member</th>
                                <th class="px-4 py-2 text-right">Raw score</th>
                                <th class="px-4 py-2 text-right">Weighted %</th>
                                <th class="px-4 py-2">Award</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($division['rows'] as $row)
                                <tr class="hover:bg-stone-50 transition-colors">
                                    <td class="px-4 py-2.5 tabular-nums text-stone-500">{{ $row['rank'] }}</td>
                                    <td class="px-4 py-2.5 font-medium text-stone-900">{{ $row['name'] }}</td>
                                    <td class="px-4 py-2.5 text-stone-600">{{ $row['saprf_number'] ?: '—' }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format($row['raw_score'], 2) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">
                                        {{ $row['weighted_percentage'] !== null ? number_format($row['weighted_percentage'], 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-2.5">
                                        @if ($row['award'] === 'Gold')
                                            <span class="inline-flex rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-inset ring-amber-200">Gold</span>
                                        @elseif ($row['award'] === 'Silver')
                                            <span class="inline-flex rounded-md bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-700 ring-1 ring-inset ring-stone-300">Silver</span>
                                        @elseif ($row['award'] === 'Bronze')
                                            <span class="inline-flex rounded-md bg-orange-50 px-2 py-0.5 text-xs font-semibold text-orange-800 ring-1 ring-inset ring-orange-200">Bronze</span>
                                        @else
                                            <span class="text-stone-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-stone-300 bg-white px-4 py-10 text-center text-sm text-stone-500">
                No eligible shooters on the {{ $seriesLabel }} {{ $season }} national log.
            </div>
        @endforelse
    </div>
</x-layouts.app>
