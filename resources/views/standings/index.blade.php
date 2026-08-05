<x-layouts.app :title="'Standings - SAPRF'">
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
        ]);
    @endphp

    <div class="space-y-6">
        <div>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">
                Standings
                <span class="ml-2 text-sm font-semibold uppercase tracking-wider {{ $series === 'PRS' ? 'text-emerald-700' : 'text-sky-700' }} align-middle">{{ $series }}</span>
                <span class="ml-1 text-sm font-semibold uppercase tracking-wider {{ $level === 'provincial' ? 'text-blue-700' : 'text-stone-700' }} align-middle">&middot; {{ $level === 'provincial' ? 'Provincial' : 'National' }}</span>
            </h1>
            <p class="mt-1 text-sm text-stone-500">
                Official <span class="font-semibold text-stone-700">{{ $series }} {{ $level === 'provincial' ? 'Provincial' : 'National' }}</span> rankings for the {{ $season }} season.
                @if($series === 'PRS' && $level === 'provincial')
                    <span class="block mt-1 text-sm text-amber-700">PRS is a national-only series &mdash; provincial standings only exist for PR22.</span>
                @endif
            </p>
        </div>

        <h2 class="sr-only">Filters</h2>

        {{-- Row 1: Season, Series, Level --}}
        <div class="flex flex-wrap items-center gap-4">
            {{-- Season --}}
            <form method="GET" action="{{ route('standings.index') }}">
                <input type="hidden" name="series" value="{{ $series }}">
                <input type="hidden" name="level" value="{{ $level }}">
                <label for="standings_admin_season" class="sr-only">Season</label>
                <select id="standings_admin_season" name="season" onchange="this.form.submit()"
                        class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    @foreach ($seasons as $s)
                        <option value="{{ $s }}" @selected($season == $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </form>

            {{-- Series --}}
            <div class="flex gap-1 bg-stone-100 rounded-xl p-1 w-fit">
                <a href="{{ route('standings.index', array_merge($baseParams, ['series' => 'PRS'])) }}"
                   class="rounded-lg px-5 py-2 text-sm font-semibold transition {{ $series === 'PRS' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900' }}">
                    PRS
                </a>
                <a href="{{ route('standings.index', array_merge($baseParams, ['series' => 'PR22'])) }}"
                   class="rounded-lg px-5 py-2 text-sm font-semibold transition {{ $series === 'PR22' ? 'bg-sky-600 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900' }}">
                    PR22
                </a>
            </div>

            {{-- Level --}}
            <div class="flex gap-1 bg-stone-100 rounded-xl p-1 w-fit">
                <a href="{{ route('standings.index', array_merge($filterParams, ['level' => 'national'])) }}"
                   class="rounded-lg px-4 py-1.5 text-xs font-semibold transition {{ $level === 'national' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-700' }}">
                    National
                </a>
                <a href="{{ route('standings.index', array_merge($filterParams, ['level' => 'provincial'])) }}"
                   class="rounded-lg px-4 py-1.5 text-xs font-semibold transition {{ $level === 'provincial' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-700' }}">
                    Provincial
                </a>
            </div>
        </div>

        {{-- Division Filter --}}
        <div class="flex flex-wrap rounded-xl bg-white border border-stone-200 shadow-sm p-1 gap-0.5">
            <a href="{{ route('standings.index', $baseParams) }}"
               class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $isOverall ? 'bg-amber-500 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                Overall
            </a>
            @foreach($divisions as $div)
                <a href="{{ route('standings.index', array_merge($baseParams, ['division_id' => $div->id])) }}"
                   class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $divisionId === $div->id ? 'bg-amber-500 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                    {{ $div->name }}
                </a>
            @endforeach
        </div>

        @if($divisionId)
            @php $activeDiv = $divisions->firstWhere('id', $divisionId); @endphp
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                    {{ $activeDiv?->name ?? 'Division' }}
                    <a href="{{ route('standings.index', $baseParams) }}" class="ml-0.5 hover:text-amber-900">&times;</a>
                </span>
            </div>
        @endif

        {{-- Standings Table --}}
        @include('standings._table', ['standings' => $standings, 'showProvince' => true, 'showDivision' => true])

        <x-sponsors-strip placement="standings_pages" class="mt-8 border-t border-stone-200" />
    </div>
</x-layouts.app>
