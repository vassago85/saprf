<x-layouts.app :title="'Standings - SAPRF'">
    @php
        $isOverall = !$divisionId && !$categoryId;
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
            'category_id' => $categoryId,
        ]);
    @endphp

    <div class="space-y-6">
        <div>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Standings</h1>
            <p class="mt-1 text-sm text-stone-500">Official {{ $series }} rankings for the {{ $season }} season.</p>
        </div>

        {{-- Row 1: Season, Series, Level --}}
        <div class="flex flex-wrap items-center gap-4">
            {{-- Season --}}
            <form method="GET" action="{{ route('standings.index') }}">
                <input type="hidden" name="series" value="{{ $series }}">
                <input type="hidden" name="level" value="{{ $level }}">
                <select name="season" onchange="this.form.submit()"
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

        {{-- Row 2: Division + Category Filters --}}
        <div class="space-y-2">
            {{-- Division Pills --}}
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

            {{-- Category Pills --}}
            @if($categories->isNotEmpty())
                <div class="flex flex-wrap rounded-xl bg-white border border-stone-200 shadow-sm p-1 gap-0.5">
                    @foreach($categories as $cat)
                        <a href="{{ route('standings.index', array_merge($baseParams, ['category_id' => $cat->id])) }}"
                           class="px-3 py-1 rounded-lg text-xs font-semibold transition {{ $categoryId === $cat->id ? 'bg-sky-500 text-white shadow-sm' : 'text-stone-400 hover:text-stone-600 hover:bg-stone-50' }}">
                            {{ $cat->name }}
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Active Filters --}}
        @if($divisionId || $categoryId)
            <div class="flex items-center gap-2 flex-wrap">
                @if($divisionId)
                    @php $activeDiv = $divisions->firstWhere('id', $divisionId); @endphp
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                        {{ $activeDiv?->name ?? 'Division' }}
                        <a href="{{ route('standings.index', $baseParams) }}" class="ml-0.5 hover:text-amber-900">&times;</a>
                    </span>
                @endif
                @if($categoryId)
                    @php $activeCat = $categories->firstWhere('id', $categoryId); @endphp
                    <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-200">
                        {{ $activeCat?->name ?? 'Category' }}
                        <a href="{{ route('standings.index', $baseParams) }}" class="ml-0.5 hover:text-sky-900">&times;</a>
                    </span>
                @endif
                <a href="{{ route('standings.index', $baseParams) }}"
                   class="text-xs text-stone-400 hover:text-stone-600 transition">Clear all</a>
            </div>
        @endif

        {{-- Standings Table --}}
        @include('standings._table', ['standings' => $standings, 'showProvince' => true, 'showDivision' => true])

        <x-sponsors-strip placement="standings_pages" class="mt-8 border-t border-stone-200" />
    </div>
</x-layouts.app>
