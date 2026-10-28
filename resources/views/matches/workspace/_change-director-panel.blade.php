{{--
    EXCO-only ownership override. Transfers `created_by` (the source of
    truth for management rights and MD payouts) and keeps the display
    label (`match_director`) in sync.

    The "Current" line below is intentionally derived from `director_name`
    (the accessor that returns `match_director ?: creator?->name`) rather
    than `creator?->name`. The old show page did it the other way around,
    which produced the "shows SAPRF Developer while MD is Hendrie Brink"
    confusion whenever the creating account differed from the actual MD.
--}}
<div class="rounded-xl border border-stone-200 bg-white p-5"
     id="change-director"
     x-data="changeDirectorPanel({
        searchUrl: '{{ route('matches.directors.search') }}',
        currentDirectorId: {{ (int) $match->created_by }},
     })">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h3 class="text-sm font-semibold text-stone-900">Change director</h3>
            <p class="mt-0.5 text-xs text-stone-500">
                Transfers ownership of this match. The new director gains
                management rights and receives future MD payouts.
            </p>
        </div>
        <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-600">
            EXCO
        </span>
    </div>

    <div class="mt-4 rounded-lg border border-stone-200 bg-white p-3 text-sm">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Current</p>
        <p class="mt-1 font-medium text-stone-900">{{ $match->director_name ?? '—' }}</p>
        @if($match->creator && $match->creator->name !== $match->match_director)
            <p class="mt-0.5 text-xs text-stone-500">
                Account owner: {{ $match->creator->name }}
                @if($match->creator->email)
                    · {{ $match->creator->email }}
                @endif
            </p>
        @elseif($match->creator?->email)
            <p class="text-xs text-stone-500">{{ $match->creator->email }}</p>
        @endif
    </div>

    <form method="POST" action="{{ route('matches.change-director', $match) }}" class="mt-4 space-y-3" @submit="submitting = true">
        @csrf
        <input type="hidden" name="user_id" x-model="selectedId">

        <div class="relative" x-show="!selected" x-cloak>
            <input type="search"
                   x-model="query"
                   @input.debounce.300ms="search()"
                   placeholder="Search name or SAPRF number (min 2 chars)"
                   class="w-full rounded-lg border border-stone-300 bg-white text-sm py-2.5 pr-9 focus:ring-emerald-500 focus:border-emerald-500" />
            <div x-show="loading" x-cloak class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-stone-400">…</div>
        </div>

        <div x-show="results.length > 0 && !selected" x-cloak class="space-y-2">
            <template x-for="r in results" :key="r.id">
                <button type="button"
                        @click="pick(r)"
                        :disabled="r.id === currentDirectorId"
                        class="w-full text-left flex items-center justify-between gap-2 rounded-lg border border-stone-200 bg-white p-2.5 hover:bg-stone-50 disabled:opacity-50 disabled:cursor-not-allowed">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-stone-900" x-text="r.saprf_number ? r.name + ' (' + r.saprf_number + ')' : r.name"></p>
                    </div>
                    <span x-show="r.id === currentDirectorId" class="text-[10px] font-semibold text-stone-400 uppercase">Current</span>
                </button>
            </template>
        </div>

        <div x-show="query.length >= 2 && !loading && results.length === 0 && !selected" x-cloak
             class="text-xs text-stone-500">
            No matching users.
        </div>

        <div x-show="selected" x-cloak class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
            <div class="flex items-center justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-emerald-800">New Director</p>
                    <p class="mt-1 text-sm font-medium text-stone-900" x-text="selected?.saprf_number ? selected.name + ' (' + selected.saprf_number + ')' : selected?.name"></p>
                </div>
                <button type="button" @click="clearSelection()" class="text-xs font-medium text-stone-500 hover:text-stone-700">
                    Change
                </button>
            </div>
            <div class="mt-3 flex items-center gap-2">
                <flux:button type="submit" variant="primary" size="sm" x-bind:disabled="submitting">
                    <span x-show="!submitting">Transfer ownership</span>
                    <span x-show="submitting" x-cloak>Saving…</span>
                </flux:button>
            </div>
        </div>

        @error('user_id')
            <p class="text-xs text-red-600">{{ $message }}</p>
        @enderror
    </form>
</div>

@once
    @push('scripts')
        <script>
            function changeDirectorPanel(config) {
                return {
                    query: '',
                    results: [],
                    loading: false,
                    selected: null,
                    selectedId: '',
                    submitting: false,
                    currentDirectorId: config.currentDirectorId,
                    async search() {
                        if (this.query.trim().length < 2) {
                            this.results = [];
                            return;
                        }
                        this.loading = true;
                        try {
                            const res = await fetch(config.searchUrl + '?q=' + encodeURIComponent(this.query), {
                                headers: { 'Accept': 'application/json' },
                            });
                            const data = await res.json();
                            this.results = data.results || [];
                        } catch (e) {
                            this.results = [];
                        } finally {
                            this.loading = false;
                        }
                    },
                    pick(r) {
                        if (r.id === this.currentDirectorId) return;
                        this.selected = r;
                        this.selectedId = r.id;
                        this.results = [];
                        this.query = '';
                    },
                    clearSelection() {
                        this.selected = null;
                        this.selectedId = '';
                    },
                };
            }
        </script>
    @endpush
@endonce
