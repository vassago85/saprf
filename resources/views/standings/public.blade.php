<x-layouts.guest>
    <x-slot:title>Standings - SAPRF</x-slot:title>

    <x-public-nav current="standings" />

    <div class="bg-stone-50 min-h-screen">
        {{-- Page Header --}}
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
                <h1 class="font-heading text-3xl sm:text-4xl font-bold text-stone-900 tracking-tight">Season Standings</h1>
                <p class="mt-1.5 text-stone-500">Official rankings for the {{ $season }} PRS and PR22 season.</p>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6" x-data="{
            series: '{{ $activeSeries }}',
            level: '{{ $activeLevel }}',
        }">
            {{-- Controls Row 1: Season, Series, Level --}}
            <div class="flex flex-col sm:flex-row sm:items-center gap-4 mb-4">
                {{-- Season Selector --}}
                <form id="standingsForm" method="GET" action="{{ url('/standings') }}" class="flex items-center gap-2">
                    <input type="hidden" name="division" value="{{ $division }}">
                    @if($provinceFilter)
                        <input type="hidden" name="province_id" value="{{ $provinceFilter }}">
                    @endif
                    <select name="season" onchange="this.form.submit()"
                            class="rounded-xl border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                        @foreach($seasons as $s)
                            <option value="{{ $s }}" @selected($season === $s)>{{ $s }} Season</option>
                        @endforeach
                    </select>
                </form>

                {{-- Series Toggle --}}
                <div class="flex rounded-xl bg-white border border-stone-200 shadow-sm p-1 w-fit">
                    <button @click="series = 'PRS'"
                            :class="series === 'PRS' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50'"
                            class="px-5 py-2 rounded-lg text-sm font-semibold transition">
                        PRS
                    </button>
                    <button @click="series = 'PR22'"
                            :class="series === 'PR22' ? 'bg-sky-600 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50'"
                            class="px-5 py-2 rounded-lg text-sm font-semibold transition">
                        PR22
                    </button>
                </div>

                {{-- Level Toggle --}}
                <div class="flex rounded-xl bg-white border border-stone-200 shadow-sm p-1 w-fit">
                    <button @click="level = 'national'"
                            :class="level === 'national' ? 'bg-stone-900 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50'"
                            class="px-4 py-1.5 rounded-lg text-xs font-semibold transition">
                        National
                    </button>
                    <button @click="level = 'provincial'"
                            :class="level === 'provincial' ? 'bg-stone-900 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50'"
                            class="px-4 py-1.5 rounded-lg text-xs font-semibold transition">
                        Provincial
                    </button>
                </div>
            </div>

            {{-- Controls Row 2: Division + Province --}}
            <div class="flex flex-col sm:flex-row sm:items-center gap-4 mb-6">
                {{-- Division Filter --}}
                <div class="flex flex-wrap rounded-xl bg-white border border-stone-200 shadow-sm p-1 gap-0.5">
                    @foreach($divisions as $div)
                        <a href="{{ url('/standings') . '?' . http_build_query(array_filter(['season' => $season, 'division' => $div, 'province_id' => $provinceFilter])) }}"
                           class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $division === $div ? 'bg-amber-500 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                            {{ $div }}
                        </a>
                    @endforeach
                </div>

                {{-- Province Filter --}}
                <form method="GET" action="{{ url('/standings') }}" class="flex items-center gap-2">
                    <input type="hidden" name="season" value="{{ $season }}">
                    <input type="hidden" name="division" value="{{ $division }}">
                    <select name="province_id" onchange="this.form.submit()"
                            class="rounded-xl border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                        <option value="">All Provinces</option>
                        @foreach($provinces as $prov)
                            <option value="{{ $prov->id }}" @selected($provinceFilter === $prov->id)>{{ $prov->name }}</option>
                        @endforeach
                    </select>
                </form>

                {{-- Active filter badges --}}
                @if($division !== 'Overall' || $provinceFilter)
                    <div class="flex items-center gap-2">
                        @if($division !== 'Overall')
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                                {{ $division }}
                                <a href="{{ url('/standings') . '?' . http_build_query(array_filter(['season' => $season, 'division' => 'Overall', 'province_id' => $provinceFilter])) }}"
                                   class="ml-0.5 hover:text-amber-900">&times;</a>
                            </span>
                        @endif
                        @if($provinceFilter)
                            @php $activeProvince = $provinces->firstWhere('id', $provinceFilter); @endphp
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                                {{ $activeProvince?->name ?? 'Province' }}
                                <a href="{{ url('/standings') . '?' . http_build_query(array_filter(['season' => $season, 'division' => $division])) }}"
                                   class="ml-0.5 hover:text-emerald-900">&times;</a>
                            </span>
                        @endif
                        <a href="{{ url('/standings') . '?season=' . $season }}"
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

            {{-- Standings Tables --}}
            <div x-show="series === 'PRS' && level === 'national'" x-cloak>
                @include('standings._public-table', ['standings' => $prsNational, 'showProvince' => true, 'series' => 'PRS', 'season' => $season])
            </div>
            <div x-show="series === 'PRS' && level === 'provincial'" x-cloak>
                @include('standings._public-table', ['standings' => $prsProvincial, 'showProvince' => true, 'series' => 'PRS', 'season' => $season])
            </div>
            <div x-show="series === 'PR22' && level === 'national'" x-cloak>
                @include('standings._public-table', ['standings' => $pr22National, 'showProvince' => true, 'series' => 'PR22', 'season' => $season])
            </div>
            <div x-show="series === 'PR22' && level === 'provincial'" x-cloak>
                @include('standings._public-table', ['standings' => $pr22Provincial, 'showProvince' => true, 'series' => 'PR22', 'season' => $season])
            </div>
        </div>
    </div>

    <x-sponsors-strip placement="standings_pages" class="border-t border-stone-200" />
    <x-public-footer />
</x-layouts.guest>
