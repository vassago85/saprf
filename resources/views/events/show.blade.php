@auth
<x-layouts.app :title="$match->name . ' - SAPRF'">
@else
<x-layouts.guest>
    <x-slot:title>{{ $match->name }} - SAPRF</x-slot:title>

    <x-public-nav current="events" />
@endauth

    <div class="bg-stone-50 min-h-screen">
        {{-- Hero / Header --}}
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
                <a href="/events?tab={{ $match->status === 'completed' ? 'results' : 'upcoming' }}"
                   class="inline-flex items-center gap-1.5 text-sm text-stone-500 hover:text-emerald-700 transition mb-5">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    Back to Events
                </a>

                <div class="flex flex-wrap items-center gap-2.5 mb-4">
                    <x-discipline-chip :discipline="$match->match_type" />
                    <x-level-chip :level="$match->series_level" />
                    @if($match->status === 'completed')
                        <x-status-chip status="completed" />
                    @else
                        <x-status-chip :status="$match->registration_status" />
                    @endif
                    @if($match->is_featured)
                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700 ring-1 ring-inset ring-amber-600/20">Featured</span>
                    @endif
                </div>

                <h1 class="font-heading text-3xl sm:text-4xl font-bold text-stone-900 tracking-tight mb-2">{{ $match->name }}</h1>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-stone-500">
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="size-4 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                        @if($match->isMultiDay())
                            {{ $match->match_date->format('l, j') }}–{{ $match->match_end_date->format('j F Y') }}
                        @else
                            {{ $match->match_date->format('l, j F Y') }}
                        @endif
                    </span>
                    @if($match->venue_name)
                        <span class="inline-flex items-center gap-1.5 text-stone-700 font-medium">
                            <svg class="size-4 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 0 1 15 0Z" /></svg>
                            {{ $match->venue_name }}
                        </span>
                    @endif
                    @if($match->location_display)
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="size-4 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                            {{ $match->location_display }}
                        </span>
                    @endif
                    @if($match->creator)
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="size-4 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                            MD: {{ $match->creator->name }}
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Main Content --}}
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                {{-- ═══ Main Column ═══ --}}
                <div class="lg:col-span-2 space-y-6">
                    {{-- Description --}}
                    @if($match->description)
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                            <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-3">About This Event</h2>
                            <div class="prose prose-sm prose-stone max-w-none">
                                {!! nl2br(e($match->description)) !!}
                            </div>
                        </div>
                    @endif

                    {{-- Match Details --}}
                    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-4">Match Details</h2>
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
                            <div>
                                <dt class="text-xs text-stone-400">Date</dt>
                                <dd class="mt-0.5 text-sm font-medium text-stone-900">{{ $match->match_date->format('l, j F Y') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-stone-400">Discipline</dt>
                                <dd class="mt-1"><x-discipline-chip :discipline="$match->match_type" /></dd>
                            </div>
                            @if($match->venue_name)
                                <div>
                                    <dt class="text-xs text-stone-400">Venue</dt>
                                    <dd class="mt-0.5 text-sm font-medium text-stone-900">{{ $match->venue_name }}</dd>
                                </div>
                            @endif
                            @if($match->location_display)
                                <div>
                                    <dt class="text-xs text-stone-400">Location</dt>
                                    <dd class="mt-0.5 text-sm text-stone-700">{{ $match->location_display }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-xs text-stone-400">Match Level</dt>
                                <dd class="mt-1"><x-level-chip :level="$match->series_level" /></dd>
                            </div>
                            @if($match->creator)
                                <div>
                                    <dt class="text-xs text-stone-400">Match Director</dt>
                                    <dd class="mt-0.5 text-sm text-stone-700">{{ $match->creator->name }}</dd>
                                </div>
                            @endif
                            @if($match->season)
                                <div>
                                    <dt class="text-xs text-stone-400">Season</dt>
                                    <dd class="mt-0.5 text-sm text-stone-700">{{ $match->season }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    {{-- Results Table (if completed) --}}
                    @if($match->scores->isNotEmpty())
                        @php
                            $scoreData = $match->scores->sortBy('overall_rank')->map(function ($score) {
                                $scoreCats = $score->categories->where('slug', '!=', 'overall');
                                return [
                                    'id' => $score->id,
                                    'name' => $score->shooter_name,
                                    'raw' => (float) $score->raw_score,
                                    'norm' => $score->normalized_score ? round((float) $score->normalized_score, 2) : null,
                                    'div_norm' => $score->division_normalized_score ? round((float) $score->division_normalized_score, 2) : null,
                                    'overall_rank' => $score->overall_rank,
                                    'div_rank' => $score->division_rank,
                                    'div_id' => $score->division_id,
                                    'div_name' => $score->division?->name ?? '—',
                                    'cat_names' => $scoreCats->pluck('name')->values()->toArray(),
                                    'cats' => $scoreCats->map(fn ($c) => [
                                        'id' => $c->id,
                                        'name' => $c->name,
                                        'norm' => $c->pivot->category_normalized_score ? round((float) $c->pivot->category_normalized_score, 2) : null,
                                        'rank' => $c->pivot->category_rank,
                                    ])->values()->toArray(),
                                ];
                            })->values()->toArray();

                            $divList = $divisions->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->toArray();
                            $catList = $categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->toArray();
                        @endphp

                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden"
                             x-data="matchResults({{ Js::from($scoreData) }}, {{ Js::from($divList) }}, {{ Js::from($catList) }})">
                            <div class="px-6 py-4 border-b border-stone-100">
                                <div class="flex items-center justify-between mb-3">
                                    <h2 class="text-lg font-semibold text-stone-900">Results</h2>
                                    <span class="text-xs text-stone-400">{{ $match->scores->count() }} {{ Str::plural('shooter', $match->scores->count()) }}</span>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <div class="flex rounded-lg bg-stone-100 p-0.5 w-fit">
                                        <button @click="setFilter('overall')" :class="mode === 'overall' ? 'bg-white shadow-sm text-stone-900' : 'text-stone-500 hover:text-stone-700'" class="px-3 py-1 rounded-md text-xs font-semibold transition">Overall</button>
                                        <button @click="setFilter('division')" :class="mode === 'division' ? 'bg-white shadow-sm text-stone-900' : 'text-stone-500 hover:text-stone-700'" class="px-3 py-1 rounded-md text-xs font-semibold transition">By Division</button>
                                        <button @click="setFilter('category')" :class="mode === 'category' ? 'bg-white shadow-sm text-stone-900' : 'text-stone-500 hover:text-stone-700'" class="px-3 py-1 rounded-md text-xs font-semibold transition">By Category</button>
                                    </div>
                                    {{-- Division sub-filter --}}
                                    <template x-if="mode === 'division' && divs.length > 1">
                                        <div class="flex rounded-lg bg-amber-50 p-0.5 w-fit">
                                            <template x-for="d in divs" :key="d.id">
                                                <button @click="subFilter = d.id" :class="subFilter === d.id ? 'bg-white shadow-sm text-amber-800' : 'text-amber-600 hover:text-amber-800'" class="px-2.5 py-1 rounded-md text-xs font-semibold transition" x-text="d.name"></button>
                                            </template>
                                        </div>
                                    </template>
                                    {{-- Category sub-filter --}}
                                    <template x-if="mode === 'category' && cats.length > 0">
                                        <div class="flex rounded-lg bg-sky-50 p-0.5 w-fit">
                                            <template x-for="c in cats" :key="c.id">
                                                <button @click="subFilter = c.id" :class="subFilter === c.id ? 'bg-white shadow-sm text-sky-800' : 'text-sky-600 hover:text-sky-800'" class="px-2.5 py-1 rounded-md text-xs font-semibold transition" x-text="c.name"></button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="min-w-full">
                                    <thead class="border-b border-stone-200 bg-stone-50">
                                        <tr>
                                            <th class="px-5 py-3 text-center text-[11px] font-bold uppercase tracking-wider text-stone-400 w-14">#</th>
                                            <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-stone-400">Shooter</th>
                                            <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-stone-400">Division / Category</th>
                                            <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-stone-400">Impacts</th>
                                            <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-stone-400">% Score</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="row in filteredRows" :key="row.id">
                                            <tr class="border-b border-stone-50 hover:bg-stone-50/50"
                                                :class="{
                                                    'bg-amber-50/50': row._rank === 1,
                                                    'bg-stone-50/50': row._rank === 2,
                                                    'bg-orange-50/30': row._rank === 3,
                                                }">
                                                <td class="px-5 py-3 text-center">
                                                    <template x-if="row._rank <= 3">
                                                        <span class="inline-flex items-center justify-center size-7 rounded-full text-sm font-bold"
                                                              :class="{
                                                                  'bg-amber-100 text-amber-700': row._rank === 1,
                                                                  'bg-stone-200 text-stone-600': row._rank === 2,
                                                                  'bg-amber-50 text-amber-600': row._rank === 3,
                                                              }" x-text="row._rank"></span>
                                                    </template>
                                                    <template x-if="row._rank > 3">
                                                        <span class="text-sm text-stone-400" x-text="row._rank"></span>
                                                    </template>
                                                </td>
                                                <td class="px-5 py-3 text-sm font-medium text-stone-900" x-text="row.name"></td>
                                                <td class="px-5 py-3">
                                                    <div class="flex flex-wrap items-center gap-1">
                                                        <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200" x-text="row.div_name"></span>
                                                        <template x-for="cat in row.cat_names" :key="cat">
                                                            <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-200" x-text="cat"></span>
                                                        </template>
                                                    </div>
                                                </td>
                                                <td class="px-5 py-3 text-sm text-stone-700 text-right tabular-nums" x-text="row.raw.toFixed(1)"></td>
                                                <td class="px-5 py-3 text-sm font-semibold text-emerald-700 text-right tabular-nums" x-text="row._norm !== null ? row._norm.toFixed(2) : '—'"></td>
                                            </tr>
                                        </template>
                                        <template x-if="filteredRows.length === 0">
                                            <tr>
                                                <td colspan="5" class="px-5 py-12 text-center text-sm text-stone-400">No results for this filter.</td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <script>
                        document.addEventListener('alpine:init', () => {
                            Alpine.data('matchResults', (scores, divs, cats) => ({
                                scores,
                                divs,
                                cats,
                                mode: 'overall',
                                subFilter: null,

                                setFilter(m) {
                                    this.mode = m;
                                    if (m === 'division' && this.divs.length) this.subFilter = this.divs[0].id;
                                    else if (m === 'category' && this.cats.length) this.subFilter = this.cats[0].id;
                                    else this.subFilter = null;
                                },

                                get filteredRows() {
                                    let rows;
                                    if (this.mode === 'division' && this.subFilter) {
                                        rows = this.scores
                                            .filter(s => s.div_id === this.subFilter)
                                            .map(s => ({...s, _norm: s.div_norm, _rank: s.div_rank }))
                                            .sort((a, b) => (a._rank ?? 999) - (b._rank ?? 999));
                                    } else if (this.mode === 'category' && this.subFilter) {
                                        const catId = this.subFilter;
                                        rows = this.scores
                                            .filter(s => s.cats.some(c => c.id === catId))
                                            .map(s => {
                                                const cat = s.cats.find(c => c.id === catId);
                                                return {...s, _norm: cat?.norm ?? s.norm, _rank: cat?.rank ?? 999 };
                                            })
                                            .sort((a, b) => (a._rank ?? 999) - (b._rank ?? 999));
                                    } else {
                                        rows = this.scores
                                            .map(s => ({...s, _norm: s.norm, _rank: s.overall_rank }))
                                            .sort((a, b) => (a._rank ?? 999) - (b._rank ?? 999));
                                    }
                                    return rows;
                                },
                            }));
                        });
                        </script>
                    @elseif($match->status !== 'completed')
                        {{-- No results yet for upcoming events --}}
                    @else
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-12 text-center">
                            <div class="inline-flex items-center justify-center size-16 rounded-2xl bg-stone-100 mb-4">
                                <svg class="size-8 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                            </div>
                            <h3 class="text-lg font-semibold text-stone-700">No results yet</h3>
                            <p class="mt-1 text-sm text-stone-400">Results will be published after the match is completed.</p>
                        </div>
                    @endif
                </div>

                {{-- ═══ Sidebar ═══ --}}
                <div class="space-y-6">
                    {{-- Registration Card --}}
                    @if($match->status !== 'completed')
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                            <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-4">Registration</h2>

                            {{-- Status indicator --}}
                            <div class="mb-4">
                                <x-status-chip :status="$match->registration_status" />
                            </div>

                            <dl class="space-y-3 mb-5">
                                @if($match->registration_open_date)
                                    <div class="flex items-center justify-between">
                                        <dt class="text-xs text-stone-400">Opens</dt>
                                        <dd class="text-sm font-medium text-stone-700">{{ $match->registration_open_date->format('j M Y') }}</dd>
                                    </div>
                                @endif
                                @if($match->registration_close_date)
                                    <div class="flex items-center justify-between">
                                        <dt class="text-xs text-stone-400">Closes</dt>
                                        <dd class="text-sm font-medium {{ $match->registration_close_date->isFuture() && $match->registration_close_date->diffInDays(now()) <= 2 ? 'text-red-600' : 'text-stone-700' }}">
                                            {{ $match->registration_close_date->format('j M Y') }}
                                        </dd>
                                    </div>
                                @endif
                                <div class="flex items-center justify-between">
                                    <dt class="text-xs text-stone-400">Member Fee</dt>
                                    <dd class="text-sm font-semibold text-emerald-700">R {{ number_format($match->active_member_fee, 2) }}</dd>
                                </div>
                                @if($match->non_member_fee > 0)
                                    <div class="flex items-center justify-between">
                                        <dt class="text-xs text-stone-400">Non-Member Fee</dt>
                                        <dd class="text-sm font-medium text-stone-700">R {{ number_format($match->non_member_fee, 2) }}</dd>
                                    </div>
                                @endif
                                @if($match->max_competitors)
                                    <div class="flex items-center justify-between">
                                        <dt class="text-xs text-stone-400">Capacity</dt>
                                        <dd class="text-sm font-medium text-stone-700">
                                            {{ $match->confirmedRegistrationCount() }} / {{ $match->max_competitors }}
                                        </dd>
                                    </div>
                                    @if($match->available_slots !== null && $match->available_slots <= 10 && $match->available_slots > 0)
                                        <p class="text-xs font-semibold text-red-600">Only {{ $match->available_slots }} {{ Str::plural('slot', $match->available_slots) }} left</p>
                                    @endif
                                @endif
                            </dl>

                            {{-- Smart CTA --}}
                            <x-event-registration-cta :match="$match" size="lg" class="w-full text-center" />

                            @if($match->non_member_fee > 0 && $match->non_member_fee != $match->active_member_fee)
                                <p class="mt-3 text-[11px] text-stone-400 text-center">Non-member scores may not count for season logs.</p>
                            @endif
                        </div>
                    @endif

                    {{-- Stats --}}
                    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-3">Stats</h2>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-2xl font-bold text-stone-900">{{ $match->registrations_count ?? 0 }}</p>
                                <p class="text-xs text-stone-400 mt-0.5">Registered</p>
                            </div>
                            @if($match->status === 'completed')
                                <div>
                                    <p class="text-2xl font-bold text-stone-900">{{ $match->scores_count ?? 0 }}</p>
                                    <p class="text-xs text-stone-400 mt-0.5">Scored</p>
                                </div>
                            @elseif($match->max_competitors)
                                <div>
                                    <p class="text-2xl font-bold text-stone-900">{{ $match->max_competitors }}</p>
                                    <p class="text-xs text-stone-400 mt-0.5">Max Capacity</p>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Season Info --}}
                    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-3">Season Info</h2>
                        <dl class="space-y-2.5 text-sm">
                            <div class="flex items-center justify-between">
                                <dt class="text-stone-500">Season</dt>
                                <dd class="font-medium text-stone-900">{{ $match->season ?: '—' }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-stone-500">Series</dt>
                                <dd class="font-medium text-stone-900">{{ $match->series ?: $match->match_type }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>

@auth
</x-layouts.app>
@else
    <x-sponsors-strip placement="match_pages" class="border-t border-stone-200" />
    <x-public-footer />
</x-layouts.guest>
@endauth
