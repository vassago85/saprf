<x-layouts.public title="Standings - SAPRF" description="Official SAPRF national and provincial standings for PRS and PR22. View rankings, match points, and shooter results by season." current="standings" sponsor-placement="standings_pages">
    @php
        $isOverall = !$divisionId;
        $baseParams = array_filter([
            'season' => $season,
            'series' => $series,
            'level' => $level,
        ]);
        $filterParams = array_filter([
            'season' => $season,
            'series' => $series,
            'level' => $level,
            'division_id' => $divisionId,
            'province_id' => $provinceFilter,
        ]);
    @endphp

    <div class="bg-stone-50 min-h-screen">
        {{-- Page Header --}}
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
                <h1 class="font-heading text-3xl sm:text-4xl font-bold text-stone-900 tracking-tight">
                    Season Standings
                    <span class="ml-2 text-base font-semibold uppercase tracking-wider {{ $series === 'PRS' ? 'text-emerald-700' : 'text-sky-700' }} align-middle">{{ $series }}</span>
                    <span class="ml-1 text-base font-semibold uppercase tracking-wider {{ $level === 'provincial' ? 'text-blue-700' : 'text-stone-700' }} align-middle">&middot; {{ $level === 'provincial' ? 'Provincial' : 'National' }}</span>
                </h1>
                <p class="mt-1.5 text-stone-500">
                    Official <span class="font-semibold text-stone-700">{{ $series }} {{ $level === 'provincial' ? 'Provincial' : 'National' }}</span> rankings for the {{ $season }} season.
                </p>
            </div>
        </div>

        {{-- Leaderboard Sponsor (above the table) --}}
        <x-sponsors-strip placement="leaderboard" class="bg-white border-b border-stone-200 !py-4" />

        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
            <h2 class="sr-only">Filters</h2>
            {{-- Row 1: Season, Series, Level --}}
            <div class="flex flex-col sm:flex-row sm:items-center gap-4 mb-4">
                {{-- Season --}}
                <form method="GET" action="{{ url('/standings') }}" class="flex items-center gap-2">
                    <input type="hidden" name="series" value="{{ $series }}">
                    <input type="hidden" name="level" value="{{ $level }}">
                    <label for="standings_season" class="sr-only">Season</label>
                    <select id="standings_season" name="season" onchange="this.form.submit()"
                            class="rounded-xl border border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                        @foreach($seasons as $s)
                            <option value="{{ $s }}" @selected($season === $s)>{{ $s }} Season</option>
                        @endforeach
                    </select>
                </form>

                {{-- Series --}}
                <div class="flex rounded-xl bg-white border border-stone-200 shadow-sm p-1 w-fit">
                    <a href="{{ url('/standings') . '?' . http_build_query(array_merge($baseParams, ['series' => 'PRS'])) }}"
                       class="px-5 py-2 rounded-lg text-sm font-semibold transition {{ $series === 'PRS' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                        PRS
                    </a>
                    <a href="{{ url('/standings') . '?' . http_build_query(array_merge($baseParams, ['series' => 'PR22'])) }}"
                       class="px-5 py-2 rounded-lg text-sm font-semibold transition {{ $series === 'PR22' ? 'bg-sky-600 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                        PR22
                    </a>
                </div>

                {{-- Level --}}
                <div class="flex rounded-xl bg-white border border-stone-200 shadow-sm p-1 w-fit">
                    <a href="{{ url('/standings') . '?' . http_build_query(array_merge($filterParams, ['level' => 'national'])) }}"
                       class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $level === 'national' ? 'bg-stone-900 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                        National
                    </a>
                    <a href="{{ url('/standings') . '?' . http_build_query(array_merge($filterParams, ['level' => 'provincial'])) }}"
                       class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $level === 'provincial' ? 'bg-stone-900 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                        Provincial
                    </a>
                </div>
            </div>

            {{-- Row 2: Division + Province --}}
            <div class="flex flex-col sm:flex-row sm:items-start gap-4 mb-6">
                <div class="flex flex-wrap rounded-xl bg-white border border-stone-200 shadow-sm p-1 gap-0.5">
                    <a href="{{ url('/standings') . '?' . http_build_query(array_filter(array_merge($baseParams, ['province_id' => $provinceFilter]))) }}"
                       class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $isOverall ? 'bg-amber-500 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                        Overall
                    </a>
                    @foreach($divisions as $div)
                        <a href="{{ url('/standings') . '?' . http_build_query(array_filter(array_merge($baseParams, ['division_id' => $div->id, 'province_id' => $provinceFilter]))) }}"
                           class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $divisionId === $div->id ? 'bg-amber-500 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                            {{ $div->name }}
                        </a>
                    @endforeach
                </div>

                <form method="GET" action="{{ url('/standings') }}" class="flex items-center gap-2">
                    <input type="hidden" name="season" value="{{ $season }}">
                    <input type="hidden" name="series" value="{{ $series }}">
                    <input type="hidden" name="level" value="{{ $level }}">
                    @if($divisionId)
                        <input type="hidden" name="division_id" value="{{ $divisionId }}">
                    @endif
                    <label for="standings_province" class="sr-only">Province</label>
                    <select id="standings_province" name="province_id" onchange="this.form.submit()"
                            class="rounded-xl border border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                        <option value="">All Provinces</option>
                        @foreach($provinces as $prov)
                            <option value="{{ $prov->id }}" @selected($provinceFilter === $prov->id)>{{ $prov->name }}</option>
                        @endforeach
                    </select>
                </form>

                @if($divisionId || $provinceFilter)
                    <div class="flex items-center gap-2 flex-wrap">
                        @if($divisionId)
                            @php $activeDiv = $divisions->firstWhere('id', $divisionId); @endphp
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                                {{ $activeDiv?->name ?? 'Division' }}
                                <a href="{{ url('/standings') . '?' . http_build_query(array_filter(array_merge($baseParams, ['province_id' => $provinceFilter]))) }}"
                                   class="ml-0.5 hover:text-amber-900">&times;</a>
                            </span>
                        @endif
                        @if($provinceFilter)
                            @php $activeProvince = $provinces->firstWhere('id', $provinceFilter); @endphp
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                                {{ $activeProvince?->name ?? 'Province' }}
                                <a href="{{ url('/standings') . '?' . http_build_query(array_filter(array_merge($baseParams, ['division_id' => $divisionId]))) }}"
                                   class="ml-0.5 hover:text-emerald-900">&times;</a>
                            </span>
                        @endif
                        <a href="{{ url('/standings') . '?' . http_build_query($baseParams) }}"
                           class="text-xs text-stone-400 hover:text-stone-600 transition">Clear all</a>
                    </div>
                @endif
            </div>

            {{-- Summary Cards --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                    <p class="text-2xl font-bold text-stone-900">{{ $totalRanked }}</p>
                    <p class="text-xs text-stone-400 mt-0.5">Ranked Shooters</p>
                </div>
                <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                    <p class="text-2xl font-bold text-stone-900">{{ $totalMatches }}</p>
                    <p class="text-xs text-stone-400 mt-0.5">Matches This Season</p>
                </div>
                <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                    <p class="text-2xl font-bold text-stone-900">{{ $completedMatches }}</p>
                    <p class="text-xs text-stone-400 mt-0.5">Completed</p>
                </div>
                <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                    <p class="text-2xl font-bold text-stone-900">{{ $remainingMatches }}</p>
                    <p class="text-xs text-stone-400 mt-0.5">Remaining</p>
                </div>
            </div>

            {{-- Standings Table --}}
            <h2 class="sr-only">Standings</h2>
            @include('standings._public-table', [
                'standings' => $standings,
                'showProvince' => true,
                'showDivision' => true,
                'series' => $series,
                'season' => $season,
                'sort' => $sort,
                'direction' => $direction,
                'filterParams' => $filterParams,
            ])
        </div>
    </div>

</x-layouts.public>
