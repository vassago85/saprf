<x-layouts.app :title="'Federation Colours - SAPRF'">
    @php
        $downloadQuery = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $seriesQuery = fn (string $next) => array_merge($downloadQuery, ['series' => $next]);
    @endphp
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
                <a href="{{ route('reports.federation-colours.csv', $downloadQuery) }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-stone-300 bg-white px-4 py-2.5 text-sm font-semibold text-stone-800 shadow-sm hover:bg-stone-50 transition">
                    Download CSV
                </a>
                <a href="{{ route('reports.federation-colours.pdf', $downloadQuery) }}"
                   class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 transition">
                    Download PDF
                </a>
            </div>
        </div>

        @if ($thresholdError)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $thresholdError }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
        @endif

        <form method="GET" class="rounded-xl border border-stone-200 bg-white shadow-sm p-4 space-y-4">
            <input type="hidden" name="series" value="{{ $series }}">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Series</label>
                    <div class="flex rounded-lg bg-stone-50 border border-stone-200 p-1 w-fit">
                        <a href="{{ route('reports.federation-colours', $seriesQuery('PRS')) }}"
                           class="px-4 py-1.5 rounded text-sm font-semibold transition {{ $series === 'PRS' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:bg-stone-100' }}">Centrefire</a>
                        <a href="{{ route('reports.federation-colours', $seriesQuery('PR22')) }}"
                           class="px-4 py-1.5 rounded text-sm font-semibold transition {{ $series === 'PR22' ? 'bg-sky-600 text-white shadow-sm' : 'text-stone-600 hover:bg-stone-100' }}">PR22</a>
                    </div>
                </div>
                <div>
                    <label for="season" class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Season</label>
                    <select id="season" name="season"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        @foreach($seasons as $s)
                            <option value="{{ $s }}" @selected((string) $season === (string) $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="division" class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Division</label>
                    <select id="division" name="division"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All divisions</option>
                        @foreach ($catalog['divisions'] as $division)
                            <option value="{{ $division['id'] }}" @selected((int) $filters['division'] === (int) $division['id'])>{{ $division['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="province" class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Province</label>
                    <select id="province" name="province"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All provinces</option>
                        @foreach ($catalog['provinces'] as $province)
                            <option value="{{ $province['id'] }}" @selected((int) $filters['province'] === (int) $province['id'])>{{ $province['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="top_n" class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Show</label>
                    <select id="top_n" name="top_n"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All shooters</option>
                        @foreach ([3, 5, 10, 15, 20, 25, 50] as $n)
                            <option value="{{ $n }}" @selected((int) ($filters['top_n'] ?? 0) === $n)>Top {{ $n }} per province</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="gold" class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Gold from %</label>
                    <input id="gold" name="gold" type="number" min="0" max="100" step="0.01" value="{{ $filters['gold'] }}"
                           class="w-28 rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label for="silver" class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Silver from %</label>
                    <input id="silver" name="silver" type="number" min="0" max="100" step="0.01" value="{{ $filters['silver'] }}"
                           class="w-28 rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label for="bronze" class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Bronze from %</label>
                    <input id="bronze" name="bronze" type="number" min="0" max="100" step="0.01" value="{{ $filters['bronze'] }}"
                           class="w-28 rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <label class="inline-flex items-center gap-2 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-700">
                    <input type="hidden" name="require_champs" value="0">
                    <input type="checkbox" name="require_champs" value="1" @checked($filters['require_champs'] === '1')
                           class="rounded border-stone-300 text-emerald-700 focus:ring-emerald-500">
                    Require SA Championships to award a grade
                </label>
                <button type="submit"
                        class="inline-flex items-center rounded-xl bg-stone-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-stone-800 transition">
                    Apply
                </button>
            </div>
        </form>

        <p class="text-xs text-stone-500">
            Gold is {{ number_format($filters['gold'], 2) }}% and above,
            Silver is {{ number_format($filters['silver'], 2) }}% up to that,
            Bronze is {{ number_format($filters['bronze'], 2) }}% up to Silver.
            @if ($filters['require_champs'] === '1')
                A grade is awarded only when the shooter has a valid SA Championships score in that division, unless you record a reason to award it anyway.
            @else
                Turn on the SA Championships requirement when you are awarding colours.
            @endif
        </p>

        @if (! empty($report['summary']['total_rows']))
            <div class="grid grid-cols-2 sm:grid-cols-6 gap-3">
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3 shadow-sm">
                    <div class="text-xs font-semibold uppercase text-stone-500">Shooters</div>
                    <div class="mt-1 text-2xl font-bold text-stone-900">{{ $report['summary']['total_rows'] }}</div>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3 shadow-sm">
                    <div class="text-xs font-semibold uppercase text-stone-500">Divisions</div>
                    <div class="mt-1 text-2xl font-bold text-stone-900">{{ $report['summary']['divisions'] }}</div>
                </div>
                <div class="rounded-xl border border-stone-200 bg-white px-4 py-3 shadow-sm">
                    <div class="text-xs font-semibold uppercase text-stone-500">Province rows</div>
                    <div class="mt-1 text-2xl font-bold text-stone-900">{{ $report['summary']['provinces'] }}</div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 shadow-sm">
                    <div class="text-xs font-semibold uppercase text-amber-800">Gold</div>
                    <div class="mt-1 text-2xl font-bold text-amber-900">{{ $report['summary']['gold'] }}</div>
                </div>
                <div class="rounded-xl border border-stone-300 bg-stone-100 px-4 py-3 shadow-sm">
                    <div class="text-xs font-semibold uppercase text-stone-600">Silver</div>
                    <div class="mt-1 text-2xl font-bold text-stone-800">{{ $report['summary']['silver'] }}</div>
                </div>
                <div class="rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 shadow-sm">
                    <div class="text-xs font-semibold uppercase text-orange-800">Bronze</div>
                    <div class="mt-1 text-2xl font-bold text-orange-900">{{ $report['summary']['bronze'] }}</div>
                </div>
            </div>
        @endif

        @forelse ($report['divisions'] as $division)
            <section class="space-y-4">
                <h2 class="font-heading text-xl font-bold text-stone-900">{{ $division['name'] }}</h2>

                @foreach ($division['provinces'] as $province)
                    <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
                        <div class="border-b border-stone-200 px-5 py-3 flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-stone-900">{{ $province['name'] }}</h3>
                                <p class="text-xs text-stone-500">
                                    Highest: {{ $province['leader_name'] }}
                                    ({{ number_format($province['leader_points'], 2) }} pts = 100%)
                                </p>
                            </div>
                            @if (isset($province['total_shooters']) && $province['total_shooters'] > count($province['rows']))
                                <span class="shrink-0 rounded-full bg-stone-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-stone-600">
                                    Showing {{ count($province['rows']) }} of {{ $province['total_shooters'] }}
                                </span>
                            @endif
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
                                        <th class="px-5 py-3">SA Champs</th>
                                        <th class="px-5 py-3">Grade</th>
                                        @if ($filters['require_champs'] === '1')
                                            <th class="px-5 py-3">Override</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-100">
                                    @foreach ($province['rows'] as $row)
                                        @php
                                            // Prefer the canonical /shooters/{saprfNumber}/{season} URL when
                                            // the shooter has a SAPRF number on file. Fall back to the legacy
                                            // /standings/{season}/shooter/{user_id} URL otherwise — the legacy
                                            // route 301-redirects to the canonical when a number exists, and
                                            // renders directly when it doesn't, so both paths land on the
                                            // same profile page from any report row.
                                            $shooterUrl = ! empty($row['saprf_number'])
                                                ? route('shooters.show.season', ['saprfNumber' => $row['saprf_number'], 'season' => $season])
                                                : route('standings.shooter', ['season' => $season, 'user' => $row['user_id']]);
                                        @endphp
                                        <tr class="hover:bg-stone-50">
                                            <td class="px-5 py-3 font-mono font-bold text-stone-900">{{ $row['rank'] }}</td>
                                            <td class="px-5 py-3 font-medium text-stone-900">
                                                <a href="{{ $shooterUrl }}" class="text-emerald-700 hover:text-emerald-900 hover:underline focus:outline-none focus:underline">
                                                    {{ $row['name'] }}
                                                </a>
                                            </td>
                                            <td class="px-5 py-3 font-mono text-xs text-stone-600">{{ $row['saprf_number'] ?? '—' }}</td>
                                            <td class="px-5 py-3 text-right font-mono text-stone-900">{{ number_format($row['points'], 2) }}</td>
                                            <td class="px-5 py-3 text-right font-mono text-stone-600">{{ $row['matches_counted'] ?? '—' }}</td>
                                            <td class="px-5 py-3 text-right font-mono font-semibold text-stone-900">
                                                {{ $row['percentage'] !== null ? number_format($row['percentage'], 2) : '—' }}
                                            </td>
                                            <td class="px-5 py-3 text-stone-600">{{ $row['shot_champs'] ? 'Yes' : 'No' }}</td>
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
                                            @if ($filters['require_champs'] === '1')
                                                <td class="px-5 py-3">
                                                    @if ($row['shot_champs'])
                                                        <span class="text-xs text-stone-400">Not needed</span>
                                                    @elseif ($row['override_id'])
                                                        <p class="text-xs text-stone-600 max-w-xs">{{ $row['override_reason'] }}</p>
                                                        <form method="POST" action="{{ route('reports.federation-colours.overrides.destroy', $row['override_id']) }}" class="mt-1">
                                                            @csrf
                                                            @method('DELETE')
                                                            @foreach ($downloadQuery as $key => $value)
                                                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                                            @endforeach
                                                            <button type="submit" class="text-xs font-semibold text-red-700 hover:text-red-800">Remove override</button>
                                                        </form>
                                                    @else
                                                        <form method="POST" action="{{ route('reports.federation-colours.overrides.store') }}" class="flex flex-col gap-1.5 max-w-xs">
                                                            @csrf
                                                            <input type="hidden" name="series" value="{{ $series }}">
                                                            <input type="hidden" name="season" value="{{ $season }}">
                                                            <input type="hidden" name="user_id" value="{{ $row['user_id'] }}">
                                                            <input type="hidden" name="division_id" value="{{ $row['division_id'] }}">
                                                            @foreach ($downloadQuery as $key => $value)
                                                                @if (! in_array($key, ['series', 'season'], true))
                                                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                                                @endif
                                                            @endforeach
                                                            <input type="text" name="reason" required minlength="8" maxlength="500"
                                                                   placeholder="Reason for awarding without SA Champs"
                                                                   class="w-full rounded-lg border border-stone-300 px-2 py-1.5 text-xs focus:ring-emerald-500 focus:border-emerald-500">
                                                            <button type="submit" class="self-start text-xs font-semibold text-emerald-800 hover:text-emerald-900">Award anyway</button>
                                                        </form>
                                                    @endif
                                                </td>
                                            @endif
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
