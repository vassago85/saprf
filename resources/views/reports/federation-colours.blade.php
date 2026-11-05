<x-layouts.app :title="'Federation Colours - SAPRF'">
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
            <div>
                <a href="{{ route('reports.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; All Reports</a>
                <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Federation Colours</h1>
                <p class="mt-1 text-sm text-stone-500 max-w-2xl">
                    Provincial season points for {{ $series === 'PR22' ? 'PR22' : 'Centrefire' }} {{ $season }}, split by the division that was shot and the shooter's home province.
                    Each percentage is of the highest person in that division and province. Open and Factory stay separate.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('reports.federation-colours.csv', ['series' => $series, 'season' => $season]) }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-semibold text-stone-800 shadow-sm hover:bg-stone-50 transition">
                    Download CSV
                </a>
                <a href="{{ route('reports.federation-colours.pdf', ['series' => $series, 'season' => $season]) }}"
                   class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 transition">
                    Download PDF
                </a>
            </div>
        </div>

        <form method="GET" class="rounded-xl border border-stone-200 bg-white shadow-sm p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Series</label>
                    <div class="flex rounded-lg bg-stone-50 border border-stone-200 p-1 w-fit">
                        <a href="{{ route('reports.federation-colours', ['series' => 'PRS', 'season' => $season]) }}"
                           class="px-4 py-1.5 rounded text-sm font-semibold transition {{ $series === 'PRS' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:bg-stone-100' }}">Centrefire</a>
                        <a href="{{ route('reports.federation-colours', ['series' => 'PR22', 'season' => $season]) }}"
                           class="px-4 py-1.5 rounded text-sm font-semibold transition {{ $series === 'PR22' ? 'bg-sky-600 text-white shadow-sm' : 'text-stone-600 hover:bg-stone-100' }}">PR22</a>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Season</label>
                    <select name="season" onchange="this.form.submit()"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        @foreach($seasons as $s)
                            <option value="{{ $s }}" @selected((string) $season === (string) $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="series" value="{{ $series }}">
                </div>
            </div>
        </form>

        <p class="text-xs text-stone-500">
            Gold is 95% and above, Silver is 85% up to 95%, Bronze is 80% up to 85%. Below 80% the percentage is shown with no grade.
        </p>

        @forelse ($report['divisions'] as $division)
            <section class="space-y-4">
                <h2 class="font-heading text-xl font-bold text-stone-900">{{ $division['name'] }}</h2>

                @foreach ($division['provinces'] as $province)
                    <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
                        <div class="border-b border-stone-200 px-5 py-3">
                            <h3 class="font-semibold text-stone-900">{{ $province['name'] }}</h3>
                            <p class="text-xs text-stone-500">
                                Highest: {{ $province['leader_name'] }}
                                ({{ number_format($province['leader_points'], 2) }} pts = 100%)
                            </p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="border-b border-stone-200 bg-stone-50">
                                    <tr class="text-left text-xs uppercase text-stone-500">
                                        <th class="px-5 py-3 w-16">Rank</th>
                                        <th class="px-5 py-3">Shooter</th>
                                        <th class="px-5 py-3">Member #</th>
                                        <th class="px-5 py-3 text-right">Points</th>
                                        <th class="px-5 py-3 text-right">Matches</th>
                                        <th class="px-5 py-3 text-right">%</th>
                                        <th class="px-5 py-3">Grade</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-100">
                                    @foreach ($province['rows'] as $row)
                                        <tr class="hover:bg-stone-50">
                                            <td class="px-5 py-3 font-mono font-bold text-stone-900">{{ $row['rank'] }}</td>
                                            <td class="px-5 py-3 font-medium text-stone-900">{{ $row['name'] }}</td>
                                            <td class="px-5 py-3 font-mono text-xs text-stone-600">{{ $row['saprf_number'] ?? '—' }}</td>
                                            <td class="px-5 py-3 text-right font-mono text-stone-900">{{ number_format($row['points'], 2) }}</td>
                                            <td class="px-5 py-3 text-right font-mono text-stone-600">{{ $row['matches_counted'] ?? '—' }}</td>
                                            <td class="px-5 py-3 text-right font-mono font-semibold text-stone-900">
                                                {{ $row['percentage'] !== null ? number_format($row['percentage'], 2) : '—' }}
                                            </td>
                                            <td class="px-5 py-3">
                                                @if ($row['grade'] === 'Gold')
                                                    <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-800 ring-1 ring-amber-200">Gold</span>
                                                @elseif ($row['grade'] === 'Silver')
                                                    <span class="inline-flex rounded-full bg-stone-200 px-2.5 py-0.5 text-xs font-bold text-stone-700 ring-1 ring-stone-300">Silver</span>
                                                @elseif ($row['grade'] === 'Bronze')
                                                    <span class="inline-flex rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-bold text-orange-800 ring-1 ring-orange-200">Bronze</span>
                                                @else
                                                    <span class="text-stone-300">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </section>
        @empty
            <div class="rounded-xl border border-stone-200 bg-white px-5 py-12 text-center text-sm text-stone-400">
                No provincial division standings for this series and season yet.
            </div>
        @endforelse
    </div>
</x-layouts.app>
