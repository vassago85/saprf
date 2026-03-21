<x-layouts.guest>
    <x-slot:title>Events - SAPRF</x-slot:title>

    <x-public-nav current="events" />

    <div class="bg-stone-50 min-h-screen">
        {{-- Page Header --}}
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div>
                        <h1 class="font-heading text-3xl sm:text-4xl font-bold text-stone-900 tracking-tight">Events</h1>
                        <p class="mt-1.5 text-stone-500">Browse upcoming matches and past results across the PRS and PR22 season.</p>
                    </div>
                    <div class="flex items-center gap-2 text-sm text-stone-500">
                        <span class="font-medium text-stone-900">{{ $events->total() }}</span>
                        {{ Str::plural('event', $events->total()) }} found
                    </div>
                </div>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
            {{-- Tab + View Toggle Row --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                {{-- Tabs: Upcoming / Results --}}
                <div class="flex rounded-xl bg-white border border-stone-200 shadow-sm p-1 w-fit">
                    <a href="{{ url('/events') }}?tab=upcoming&view={{ $view }}"
                       class="px-5 py-2 rounded-lg text-sm font-semibold transition {{ $tab === 'upcoming' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                        Upcoming
                        <span class="ml-1 inline-flex items-center justify-center rounded-full text-[10px] font-bold {{ $tab === 'upcoming' ? 'bg-emerald-600 text-emerald-50' : 'bg-stone-100 text-stone-500' }} min-w-[20px] h-5 px-1">{{ $upcomingCount }}</span>
                    </a>
                    <a href="{{ url('/events') }}?tab=results&view={{ $view }}&season={{ $season }}"
                       class="px-5 py-2 rounded-lg text-sm font-semibold transition {{ $tab === 'results' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                        Results
                        <span class="ml-1 inline-flex items-center justify-center rounded-full text-[10px] font-bold {{ $tab === 'results' ? 'bg-emerald-600 text-emerald-50' : 'bg-stone-100 text-stone-500' }} min-w-[20px] h-5 px-1">{{ $pastCount }}</span>
                    </a>
                </div>

                {{-- View Toggle --}}
                <div class="flex items-center gap-1 bg-white border border-stone-200 rounded-xl p-1 shadow-sm w-fit">
                    <a href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}"
                       class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $view === 'list' ? 'bg-stone-900 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700' }}">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z" /></svg>
                        Cards
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar']) }}"
                       class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $view === 'calendar' ? 'bg-stone-900 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700' }}">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                        Calendar
                    </a>
                </div>
            </div>

            {{-- Filter Bar --}}
            <form method="GET" action="{{ url('/events') }}" id="filterForm"
                  class="bg-white rounded-2xl border border-stone-200 shadow-sm p-4 mb-6">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="hidden" name="view" value="{{ $view }}">
                <input type="hidden" name="sort" value="{{ $sort }}">

                <div class="flex flex-wrap items-end gap-3">
                    {{-- Discipline --}}
                    <div class="w-full sm:w-auto">
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-400 mb-1">Discipline</label>
                        <select name="discipline" onchange="document.getElementById('filterForm').submit()"
                                class="w-full sm:w-auto rounded-lg border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">All</option>
                            <option value="PRS" @selected($discipline === 'PRS')>PRS</option>
                            <option value="PR22" @selected($discipline === 'PR22')>PR22</option>
                        </select>
                    </div>

                    {{-- Match Type --}}
                    <div class="w-full sm:w-auto">
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-400 mb-1">Type</label>
                        <select name="type" onchange="document.getElementById('filterForm').submit()"
                                class="w-full sm:w-auto rounded-lg border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">All Types</option>
                            <option value="club" @selected($type === 'club')>Club</option>
                            <option value="provincial" @selected($type === 'provincial')>Provincial</option>
                            <option value="national" @selected($type === 'national')>National</option>
                            <option value="final" @selected($type === 'final')>Final</option>
                            <option value="open" @selected($type === 'open')>Open</option>
                        </select>
                    </div>

                    {{-- Province --}}
                    <div class="w-full sm:w-auto">
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-400 mb-1">Province</label>
                        <select name="province_id" onchange="document.getElementById('filterForm').submit()"
                                class="w-full sm:w-auto rounded-lg border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">All Provinces</option>
                            @foreach($provinces as $p)
                                <option value="{{ $p->id }}" @selected($provinceId == $p->id)>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if($tab === 'upcoming')
                        {{-- Date Range --}}
                        <div class="w-full sm:w-auto">
                            <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-400 mb-1">When</label>
                            <select name="date_range" onchange="document.getElementById('filterForm').submit()"
                                    class="w-full sm:w-auto rounded-lg border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All Upcoming</option>
                                <option value="this_month" @selected($dateRange === 'this_month')>This Month</option>
                                <option value="next_3_months" @selected($dateRange === 'next_3_months')>Next 3 Months</option>
                            </select>
                        </div>
                    @else
                        {{-- Season --}}
                        <div class="w-full sm:w-auto">
                            <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-400 mb-1">Season</label>
                            <select name="season" onchange="document.getElementById('filterForm').submit()"
                                    class="w-full sm:w-auto rounded-lg border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500">
                                @foreach($seasons as $s)
                                    <option value="{{ $s }}" @selected($season === $s)>{{ $s }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    {{-- Search --}}
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-400 mb-1">Search</label>
                        <div class="relative">
                            <input type="text" name="search" value="{{ $search }}" placeholder="Event name, venue, city..."
                                   class="w-full rounded-lg border-stone-300 bg-white text-sm py-2 pl-9 pr-3 focus:ring-emerald-500 focus:border-emerald-500">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        </div>
                    </div>

                    {{-- Sort --}}
                    <div class="w-full sm:w-auto">
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-400 mb-1">Sort</label>
                        <select name="sort" onchange="document.getElementById('filterForm').submit()"
                                class="w-full sm:w-auto rounded-lg border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="date_asc" @selected($sort === 'date_asc')>Date (earliest)</option>
                            <option value="date_desc" @selected($sort === 'date_desc')>Date (latest)</option>
                            <option value="province" @selected($sort === 'province')>Province</option>
                            @if($tab === 'upcoming')
                                <option value="closing_soon" @selected($sort === 'closing_soon')>Closing soon</option>
                            @endif
                        </select>
                    </div>

                    <button type="submit" class="rounded-lg bg-emerald-700 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-800 transition shadow-sm">
                        Filter
                    </button>

                    @if(!empty($activeFilters))
                        <a href="{{ url('/events') }}?tab={{ $tab }}&view={{ $view }}"
                           class="rounded-lg px-3 py-2 text-sm font-medium text-stone-500 hover:text-stone-700 hover:bg-stone-50 transition">
                            Clear all
                        </a>
                    @endif
                </div>

                {{-- Active filter pills --}}
                @if(!empty($activeFilters))
                    <div class="flex flex-wrap gap-2 mt-3 pt-3 border-t border-stone-100">
                        @foreach($activeFilters as $key => $value)
                            @php
                                $removeParams = request()->except([$key]);
                                $label = match($key) {
                                    'discipline' => $value,
                                    'type' => ucfirst($value),
                                    'province_id' => $provinces->firstWhere('id', $value)?->name ?? $value,
                                    'status' => ucfirst($value),
                                    'date_range' => str_replace('_', ' ', ucfirst($value)),
                                    'search' => '"' . $value . '"',
                                    default => $value,
                                };
                            @endphp
                            <a href="{{ url('/events') }}?{{ http_build_query($removeParams) }}"
                               class="inline-flex items-center gap-1 rounded-full bg-emerald-50 text-emerald-700 px-3 py-1 text-xs font-medium ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100 transition">
                                {{ $label }}
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                            </a>
                        @endforeach
                    </div>
                @endif
            </form>

            {{-- Content Area --}}
            @if($view === 'calendar')
                {{-- Calendar View --}}
                <x-events-calendar :discipline="$discipline" :province-id="$provinceId" :type="$type" />
            @else
                {{-- Card Grid View --}}
                @if($events->isEmpty())
                    <div class="text-center py-20">
                        <div class="inline-flex items-center justify-center size-16 rounded-2xl bg-stone-100 mb-4">
                            <svg class="size-8 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                        </div>
                        <h3 class="text-lg font-semibold text-stone-700">No events found</h3>
                        <p class="mt-1 text-sm text-stone-400">
                            @if(!empty($activeFilters))
                                Try adjusting your filters.
                            @else
                                Check back soon for new events.
                            @endif
                        </p>
                    </div>
                @else
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        @foreach($events as $match)
                            <x-event-card
                                :match="$match"
                                :showPodium="$tab === 'results'" />
                        @endforeach
                    </div>

                    <div class="mt-8">
                        {{ $events->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>

    <x-sponsors-strip placement="landing_section" class="border-t border-stone-200" />
    <x-public-footer />
</x-layouts.guest>
