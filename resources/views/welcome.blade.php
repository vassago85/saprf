<x-layouts.guest description="The official South African Precision Rifle Federation platform for PRS and PR22 — register for matches, track scores, and compete in national standings.">
    <x-slot:title>SAPRF - South African Precision Rifle Federation</x-slot:title>

    <x-public-nav current="home" />

    {{-- HERO --}}
    <section class="relative overflow-hidden bg-gradient-to-br from-stone-50 via-white to-emerald-50/30" x-data="{
        matches: [],
        prsStandings: [],
        pr22Standings: [],
        loadingM: true,
        loadingPrs: true,
        loadingPr22: true,
        activeTab: 'PRS',
        init() {
            fetch('/api/v1/matches/upcoming')
                .then(r => r.json())
                .then(data => { this.matches = (data.data || []).slice(0, 3); this.loadingM = false; })
                .catch(() => { this.loadingM = false; });
            fetch('/api/v1/standings?series=PRS&limit=5')
                .then(r => r.json())
                .then(data => { this.prsStandings = data.data || []; this.loadingPrs = false; })
                .catch(() => { this.loadingPrs = false; });
            fetch('/api/v1/standings?series=PR22&limit=5')
                .then(r => r.json())
                .then(data => { this.pr22Standings = data.data || []; this.loadingPr22 = false; })
                .catch(() => { this.loadingPr22 = false; });
        },
        get currentStandings() { return this.activeTab === 'PRS' ? this.prsStandings : this.pr22Standings; },
        get currentLoading() { return this.activeTab === 'PRS' ? this.loadingPrs : this.loadingPr22; },
    }">
        {{-- Subtle grid pattern --}}
        <div class="absolute inset-0 opacity-[0.03]" style="background-image: url('data:image/svg+xml,%3Csvg width=&quot;40&quot; height=&quot;40&quot; xmlns=&quot;http://www.w3.org/2000/svg&quot;%3E%3Cpath d=&quot;M0 0h40v40H0z&quot; fill=&quot;none&quot;/%3E%3Cpath d=&quot;M40 0v40H0&quot; stroke=&quot;%23000&quot; stroke-width=&quot;0.5&quot; fill=&quot;none&quot;/%3E%3C/svg%3E');"></div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 py-16 lg:py-20">
            <div class="grid lg:grid-cols-12 gap-10 lg:gap-14 items-center">
                {{-- LEFT — Content --}}
                <div class="lg:col-span-5 xl:col-span-5">
                    <div class="inline-flex items-center gap-2 rounded-full bg-emerald-50 border border-emerald-100 px-4 py-1.5 mb-6">
                        <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">2026 Season Live</span>
                    </div>

                    <h1 class="font-heading text-4xl sm:text-5xl xl:text-[3.5rem] font-extrabold tracking-tight leading-[1.05]">
                        <span class="text-stone-900">Precision Rifle.</span><br>
                        <span class="text-stone-900">Structured. Scored.</span><br>
                        <span class="text-emerald-700">Ranked.</span>
                    </h1>

                    <p class="mt-5 text-base sm:text-lg text-stone-500 leading-relaxed max-w-md">
                        The official SAPRF platform for PRS & PR22 — register for matches, track scores, and compete in national standings.
                    </p>

                    <div class="mt-8 flex flex-col sm:flex-row gap-3">
                        <a href="/events"
                           class="inline-flex items-center justify-center gap-2 bg-emerald-700 text-white rounded-xl px-7 py-3.5 font-semibold hover:bg-emerald-800 transition shadow-md shadow-emerald-900/10 text-center">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                            Find a Match
                        </a>
                        <a href="/standings"
                           class="inline-flex items-center justify-center gap-2 border border-stone-300 text-stone-700 rounded-xl px-7 py-3.5 font-semibold hover:bg-stone-50 hover:border-stone-400 transition text-center">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-4.5A3.375 3.375 0 0 0 13.125 10.875h-2.25A3.375 3.375 0 0 0 7.5 14.25v4.5" /></svg>
                            View Standings
                        </a>
                    </div>

                    @guest
                        <p class="mt-5 text-sm text-stone-400">
                            New to SAPRF?
                            <a href="{{ route('register') }}" class="font-semibold text-emerald-700 hover:text-emerald-800 transition">Join free &rarr;</a>
                        </p>
                    @endguest
                </div>

                {{-- RIGHT — Live Data Panel --}}
                <div class="lg:col-span-7 xl:col-span-7">
                    <div class="bg-white rounded-2xl shadow-xl shadow-stone-900/8 overflow-hidden border border-stone-200 ring-1 ring-stone-100">
                        {{-- Panel header bar --}}
                        <div class="flex items-center gap-2.5 px-5 py-3 bg-stone-50 border-b border-stone-200">
                            <span class="size-2 rounded-full bg-emerald-500 animate-pulse shadow-[0_0_6px_rgba(16,185,129,0.4)]"></span>
                            <span class="text-[11px] text-stone-900 font-semibold tracking-widest uppercase">SAPRF Live</span>
                            <span class="ml-auto text-[10px] text-stone-400 font-medium tabular-nums">2026 Season</span>
                        </div>

                        <div class="grid md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-stone-200">
                            {{-- Upcoming Matches --}}
                            <div class="p-4 sm:p-5">
                                <div class="flex items-center justify-between mb-3.5">
                                    <h2 class="text-[11px] font-bold uppercase tracking-wider text-stone-500">Next Matches</h2>
                                    <a href="/events" class="text-[11px] text-emerald-600 font-semibold hover:text-emerald-700 transition">All &rarr;</a>
                                </div>

                                <template x-if="loadingM">
                                    <div class="space-y-2.5">
                                        <div class="h-[60px] rounded-xl bg-stone-100 animate-pulse"></div>
                                        <div class="h-[60px] rounded-xl bg-stone-100 animate-pulse"></div>
                                        <div class="h-[60px] rounded-xl bg-stone-100 animate-pulse"></div>
                                    </div>
                                </template>

                                <template x-if="!loadingM && matches.length === 0">
                                    <p class="text-sm text-stone-400 py-8 text-center">No upcoming matches</p>
                                </template>

                                <template x-if="!loadingM && matches.length > 0">
                                    <div class="space-y-2">
                                        <template x-for="match in matches" :key="match.id">
                                            <a :href="'/events/' + match.id"
                                               class="block rounded-xl bg-stone-50 hover:bg-stone-100 border border-stone-200 hover:border-stone-300 p-3 transition-all duration-150 group hover:-translate-y-px hover:shadow-sm">
                                                <div class="flex items-start gap-3">
                                                    <div class="flex flex-col items-center justify-center rounded-lg bg-emerald-50 border border-emerald-200 w-10 h-10 shrink-0">
                                                        <span class="text-[8px] font-bold uppercase tracking-wider text-emerald-600 leading-none" x-text="new Date(match.match_date).toLocaleDateString('en-ZA', {month: 'short'})"></span>
                                                        <span class="text-[13px] font-bold text-emerald-700 leading-tight" x-text="new Date(match.match_date).getDate()"></span>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-[13px] font-semibold text-stone-900 group-hover:text-stone-950 transition truncate leading-snug" x-text="match.name"></p>
                                                        <div class="flex items-center gap-2 mt-1">
                                                            <span class="text-[10px] font-semibold rounded px-1.5 py-0.5 leading-none"
                                                                  :class="match.match_type === 'PRS' ? 'bg-emerald-100 text-emerald-700' : 'bg-sky-100 text-sky-700'"
                                                                  x-text="match.match_type"></span>
                                                            <span class="text-[10px] text-stone-500 truncate" x-text="match.province?.abbreviation || ''"></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </a>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            {{-- Standings with Tabs --}}
                            <div class="p-4 sm:p-5">
                                {{-- The tab group is this panel's visible title; screen readers
                                     still need a heading to sit between it and the page h1. --}}
                                <h2 class="sr-only">Standings snapshot</h2>
                                <div class="flex items-center justify-between mb-3.5">
                                    <div class="flex items-center gap-0.5 rounded-lg bg-stone-100 p-0.5 border border-stone-200">
                                        <button type="button"
                                                @click="activeTab = 'PRS'"
                                                :class="activeTab === 'PRS'
                                                    ? 'bg-white text-emerald-700 shadow-sm border-stone-200'
                                                    : 'text-stone-500 hover:text-stone-700 border-transparent'"
                                                class="px-3 py-1 text-[11px] font-bold uppercase tracking-wider rounded-md transition-all duration-150 border">
                                            PRS
                                        </button>
                                        <button type="button"
                                                @click="activeTab = 'PR22'"
                                                :class="activeTab === 'PR22'
                                                    ? 'bg-white text-sky-700 shadow-sm border-stone-200'
                                                    : 'text-stone-500 hover:text-stone-700 border-transparent'"
                                                class="px-3 py-1 text-[11px] font-bold uppercase tracking-wider rounded-md transition-all duration-150 border">
                                            PR22
                                        </button>
                                    </div>
                                    <a href="/standings" class="text-[11px] text-emerald-600 font-semibold hover:text-emerald-700 transition">Full table &rarr;</a>
                                </div>

                                {{-- Column headers --}}
                                <div class="flex items-center gap-3 px-3 pb-2 mb-1 border-b border-stone-200">
                                    <span class="text-[9px] font-bold uppercase tracking-widest text-stone-400 w-5">#</span>
                                    <span class="flex-1 text-[9px] font-bold uppercase tracking-widest text-stone-400">Shooter</span>
                                    <span class="text-[9px] font-bold uppercase tracking-widest text-stone-400 w-8 text-center">Prov</span>
                                    <span class="text-[9px] font-bold uppercase tracking-widest text-stone-400 w-11 text-right">Pts</span>
                                </div>

                                <template x-if="currentLoading">
                                    <div class="space-y-2 mt-1">
                                        <div class="h-9 rounded-lg bg-stone-100 animate-pulse"></div>
                                        <div class="h-9 rounded-lg bg-stone-100 animate-pulse"></div>
                                        <div class="h-9 rounded-lg bg-stone-100 animate-pulse"></div>
                                        <div class="h-9 rounded-lg bg-stone-100 animate-pulse"></div>
                                        <div class="h-9 rounded-lg bg-stone-100 animate-pulse"></div>
                                    </div>
                                </template>

                                <template x-if="!currentLoading && currentStandings.length === 0">
                                    <p class="text-sm text-stone-400 py-8 text-center">No standings yet</p>
                                </template>

                                <div x-show="!currentLoading && currentStandings.length > 0"
                                     x-transition:enter="transition ease-out duration-200"
                                     x-transition:enter-start="opacity-0 translate-y-1"
                                     x-transition:enter-end="opacity-100 translate-y-0">
                                    <div class="space-y-0.5">
                                        <template x-for="(entry, index) in currentStandings" :key="activeTab + '-' + (entry.id || index)">
                                            <div class="flex items-center gap-3 px-3 py-2 rounded-lg transition-colors duration-100"
                                                 :class="index < 3 ? 'bg-stone-50' : 'hover:bg-stone-50'">
                                                <template x-if="index === 0">
                                                    <span class="inline-flex items-center justify-center size-5 rounded-full bg-amber-100 text-[10px] font-bold text-amber-700">1</span>
                                                </template>
                                                <template x-if="index === 1">
                                                    <span class="inline-flex items-center justify-center size-5 rounded-full bg-stone-200 text-[10px] font-bold text-stone-600">2</span>
                                                </template>
                                                <template x-if="index === 2">
                                                    <span class="inline-flex items-center justify-center size-5 rounded-full bg-amber-50 text-[10px] font-bold text-amber-600">3</span>
                                                </template>
                                                <template x-if="index >= 3">
                                                    <span class="text-[11px] font-semibold text-stone-400 w-5 text-center" x-text="index + 1"></span>
                                                </template>
                                                <span class="flex-1 min-w-0 text-[13px] font-semibold text-stone-900 truncate" x-text="entry.user?.name || 'Unknown'"></span>
                                                <span class="text-[10px] text-stone-500 font-medium w-8 text-center shrink-0" x-text="entry.user?.province?.abbreviation || ''"></span>
                                                <span class="text-[13px] font-mono font-bold w-11 text-right"
                                                      :class="activeTab === 'PRS' ? 'text-emerald-600' : 'text-sky-600'"
                                                      x-text="parseFloat(entry.points).toFixed(0)"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- UPCOMING MATCHES --}}
    <section class="bg-stone-50 py-20" x-data="{
        matches: [],
        loading: true,
        init() {
            fetch('/api/v1/matches/upcoming')
                .then(r => r.json())
                .then(data => { this.matches = (data.data || []).slice(0, 6); this.loading = false; })
                .catch(() => { this.loading = false; });
        }
    }">
        <div class="max-w-7xl mx-auto px-6">
            <div class="flex items-end justify-between mb-10">
                <div>
                    <h2 class="font-heading text-3xl font-bold text-stone-900">Upcoming Matches</h2>
                    <p class="mt-1 text-stone-500">Register for upcoming PRS and PR22 events.</p>
                </div>
                <a href="/events" class="hidden sm:inline-flex items-center gap-1 text-sm text-emerald-700 hover:text-emerald-900 font-semibold transition">
                    View all events
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>

            <template x-if="loading">
                <div class="text-center py-12">
                    <div class="inline-block size-6 border-2 border-emerald-700 border-t-transparent rounded-full animate-spin"></div>
                    <p class="mt-3 text-sm text-stone-400">Loading matches&hellip;</p>
                </div>
            </template>

            <template x-if="!loading && matches.length === 0">
                <p class="text-center text-stone-400 py-12">No upcoming matches scheduled.</p>
            </template>

            <template x-if="!loading && matches.length > 0">
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    <template x-for="match in matches" :key="match.id">
                        <a :href="'/events/' + match.id" class="group bg-white rounded-2xl border border-stone-200 shadow-[0_1px_3px_0_rgb(0_0_0/0.04)] hover:shadow-[0_4px_12px_-2px_rgb(0_0_0/0.08)] hover:border-stone-300 transition-all duration-200 p-5 flex flex-col">
                            <div class="flex items-start justify-between gap-3 mb-3">
                                <div class="flex flex-col items-center justify-center rounded-xl bg-emerald-50 border border-emerald-100 w-14 h-14 shrink-0">
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 leading-none" x-text="new Date(match.match_date).toLocaleDateString('en-ZA', {month: 'short'})"></span>
                                    <span class="text-lg font-bold text-emerald-900 leading-tight" x-text="new Date(match.match_date).getDate()"></span>
                                </div>
                                <template x-if="match.is_featured">
                                    <span class="bg-amber-50 text-amber-700 rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset ring-amber-600/20">Featured</span>
                                </template>
                            </div>
                            <h3 class="font-semibold text-stone-900 group-hover:text-emerald-800 transition leading-snug line-clamp-2 mb-2" x-text="match.name"></h3>
                            <p class="flex items-center gap-1.5 text-sm text-stone-500 mb-3 min-w-0">
                                <svg class="size-3.5 text-stone-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 0 1 15 0Z" /></svg>
                                <span class="truncate min-w-0" x-text="(match.city || match.venue_location || match.venue_name || 'TBC') + (match.province?.name ? ', ' + match.province.name : '')"></span>
                            </p>
                            <div class="mt-auto flex flex-wrap gap-1.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                      :class="match.match_type === 'PRS' ? 'bg-emerald-100 text-emerald-800 ring-emerald-600/20' : 'bg-sky-100 text-sky-800 ring-sky-600/20'"
                                      x-text="match.match_type"></span>
                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-600 ring-1 ring-inset ring-stone-500/20 capitalize" x-text="match.series_level" x-show="match.series_level"></span>
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20" x-show="match.status === 'open'">Open</span>
                            </div>
                        </a>
                    </template>
                </div>
            </template>

            <div class="text-center mt-8 sm:hidden">
                <a href="/events" class="text-sm text-emerald-700 font-semibold hover:text-emerald-900">View all events &rarr;</a>
            </div>
        </div>
    </section>

    {{-- NATIONAL STANDINGS --}}
    <section class="bg-white py-20" x-data="{
        prs: [], pr22: [], loadingPrs: true, loadingPr22: true,
        init() {
            fetch('/api/v1/standings?series=PRS&limit=5')
                .then(r => r.json())
                .then(data => { this.prs = data.data || []; this.loadingPrs = false; })
                .catch(() => { this.loadingPrs = false; });
            fetch('/api/v1/standings?series=PR22&limit=5')
                .then(r => r.json())
                .then(data => { this.pr22 = data.data || []; this.loadingPr22 = false; })
                .catch(() => { this.loadingPr22 = false; });
        },
        rankIcon(idx) {
            if (idx === 0) return 'bg-amber-100 text-amber-700';
            if (idx === 1) return 'bg-stone-200 text-stone-600';
            if (idx === 2) return 'bg-amber-50 text-amber-600';
            return '';
        }
    }">
        <div class="max-w-7xl mx-auto px-6">
            <div class="flex items-end justify-between mb-10">
                <div>
                    <h2 class="font-heading text-3xl font-bold text-stone-900">National Standings</h2>
                    <p class="mt-1 text-stone-500">Current season leaders across PRS and PR22 series.</p>
                </div>
                <a href="/standings" class="hidden sm:inline-flex items-center gap-1 text-sm text-emerald-700 hover:text-emerald-900 font-semibold transition">
                    Full standings
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>

            <div class="grid lg:grid-cols-2 gap-6">
                {{-- PRS --}}
                <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-stone-100 flex items-center gap-3">
                        <span class="text-lg font-semibold text-stone-900">PRS</span>
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">Precision Rifle Series</span>
                    </div>
                    <div class="p-5">
                        <template x-if="loadingPrs">
                            <p class="text-sm text-stone-400 text-center py-4">Loading&hellip;</p>
                        </template>
                        <template x-if="!loadingPrs && prs.length === 0">
                            <p class="text-sm text-stone-400 text-center py-4">No standings data yet.</p>
                        </template>
                        <template x-if="!loadingPrs && prs.length > 0">
                            <div class="space-y-2">
                                <template x-for="(entry, index) in prs" :key="entry.id || index">
                                    <div class="flex items-center gap-3 px-3 py-2.5 rounded-xl" :class="index < 3 ? 'bg-emerald-50/50' : 'hover:bg-stone-50'" >
                                        <template x-if="index < 3">
                                            <span class="inline-flex items-center justify-center size-7 rounded-full text-xs font-bold" :class="rankIcon(index)" x-text="index + 1"></span>
                                        </template>
                                        <template x-if="index >= 3">
                                            <span class="text-sm font-medium text-stone-400 w-7 text-center" x-text="index + 1"></span>
                                        </template>
                                        <span class="flex-1 min-w-0 text-sm font-medium text-stone-900 truncate" x-text="entry.user?.name || 'Unknown'"></span>
                                        <span class="text-xs text-stone-400 font-medium shrink-0" x-text="entry.user?.province?.abbreviation || ''"></span>
                                        <span class="text-sm font-mono font-bold text-stone-900 w-14 text-right shrink-0" x-text="parseFloat(entry.points).toFixed(0)"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- PR22 --}}
                <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-stone-100 flex items-center gap-3">
                        <span class="text-lg font-semibold text-stone-900">PR22</span>
                        <span class="inline-flex items-center rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-semibold text-sky-800">Precision Rimfire</span>
                    </div>
                    <div class="p-5">
                        <template x-if="loadingPr22">
                            <p class="text-sm text-stone-400 text-center py-4">Loading&hellip;</p>
                        </template>
                        <template x-if="!loadingPr22 && pr22.length === 0">
                            <p class="text-sm text-stone-400 text-center py-4">No standings data yet.</p>
                        </template>
                        <template x-if="!loadingPr22 && pr22.length > 0">
                            <div class="space-y-2">
                                <template x-for="(entry, index) in pr22" :key="entry.id || index">
                                    <div class="flex items-center gap-3 px-3 py-2.5 rounded-xl" :class="index < 3 ? 'bg-sky-50/50' : 'hover:bg-stone-50'">
                                        <template x-if="index < 3">
                                            <span class="inline-flex items-center justify-center size-7 rounded-full text-xs font-bold" :class="rankIcon(index)" x-text="index + 1"></span>
                                        </template>
                                        <template x-if="index >= 3">
                                            <span class="text-sm font-medium text-stone-400 w-7 text-center" x-text="index + 1"></span>
                                        </template>
                                        <span class="flex-1 min-w-0 text-sm font-medium text-stone-900 truncate" x-text="entry.user?.name || 'Unknown'"></span>
                                        <span class="text-xs text-stone-400 font-medium shrink-0" x-text="entry.user?.province?.abbreviation || ''"></span>
                                        <span class="text-sm font-mono font-bold text-stone-900 w-14 text-right shrink-0" x-text="parseFloat(entry.points).toFixed(0)"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="text-center mt-8 sm:hidden">
                <a href="/standings" class="text-sm text-emerald-700 font-semibold hover:text-emerald-900">Full standings &rarr;</a>
            </div>
        </div>
    </section>

    {{-- SPONSORS --}}
    <x-sponsors-strip placement="landing_section" class="bg-stone-50" />

    <x-public-footer />
</x-layouts.guest>
