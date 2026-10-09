{{--
    "Named individuals" picker. Rendered inside the audience-rule x-for loop,
    so `rule`, `idx` and `field` come from the parent Alpine scope. Submits
    the same comma-separated `user_ids` string the AudienceResolver expects.
--}}
<div x-data="recipientPicker(rule, field.name, @js(route('announcements.recipients.search')))"
    @click.outside="open = false"
    class="relative mt-1">
    <input type="hidden" :name="`{{ $namePrefix }}[${idx}][value][${field.name}]`" :value="rule.value[field.name] ?? ''">

    <div x-show="selected.length" class="mb-1.5 flex flex-wrap gap-1.5">
        <template x-for="user in selected" :key="user.id">
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 py-0.5 pl-2.5 pr-1 text-xs font-medium text-emerald-800 ring-1 ring-inset ring-emerald-600/20">
                <span x-text="user.name"></span>
                <button type="button" @click="remove(user.id)"
                    :aria-label="`Remove ${user.name}`"
                    class="rounded-full px-1 text-emerald-600 hover:bg-emerald-100 hover:text-emerald-900">&times;</button>
            </span>
        </template>
    </div>

    <div class="relative">
        <input type="text" x-model="query"
            @input.debounce.250ms="search()"
            @focus="open = query.trim().length >= 2"
            @keydown.escape="open = false"
            @keydown.enter.prevent="if (results.length) add(results[0])"
            placeholder="Search by name, email or SAPRF number…"
            autocomplete="off"
            class="block w-full rounded-lg border border-stone-300 px-2 py-1.5 pr-7 text-xs focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
        <svg x-show="loading" class="pointer-events-none absolute right-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 animate-spin text-stone-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
    </div>

    <div x-show="open" x-cloak
        class="absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-stone-200 bg-white shadow-lg">
        <template x-if="!loading && results.length === 0">
            <div class="px-3 py-2 text-xs text-stone-400">No matches found</div>
        </template>
        <template x-for="user in results" :key="user.id">
            <button type="button" @click="add(user)"
                :disabled="isSelected(user.id)"
                class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-xs hover:bg-emerald-50 focus:bg-emerald-50 focus:outline-none disabled:cursor-default disabled:opacity-50">
                <span class="min-w-0">
                    <span class="font-medium text-stone-800" x-text="user.name"></span>
                    <span class="ml-1 text-stone-400" x-text="user.email"></span>
                </span>
                <span class="shrink-0 text-stone-400" x-text="isSelected(user.id) ? 'Added' : (user.saprf_number || '')"></span>
            </button>
        </template>
    </div>
</div>

@once
    @push('scripts')
        <script>
            function recipientPicker(rule, fieldName, searchUrl) {
                return {
                    query: '',
                    results: [],
                    selected: [],
                    open: false,
                    loading: false,

                    async init() {
                        const ids = String(rule.value[fieldName] ?? '')
                            .split(/[\s,;]+/)
                            .filter((id) => /^\d+$/.test(id));

                        if (!ids.length) return;

                        this.selected = ids.map((id) => ({ id: Number(id), name: `User #${id}` }));

                        const found = await this.fetchUsers({ ids: ids.join(',') });
                        const byId = new Map(found.map((u) => [u.id, u]));
                        this.selected = this.selected.map((u) => byId.get(u.id) || u);
                    },

                    async search() {
                        const term = this.query.trim();
                        if (term.length < 2) {
                            this.results = [];
                            this.open = false;
                            return;
                        }

                        this.loading = true;
                        this.results = await this.fetchUsers({ q: term });
                        this.loading = false;
                        this.open = true;
                    },

                    async fetchUsers(params) {
                        try {
                            const res = await fetch(`${searchUrl}?${new URLSearchParams(params)}`, {
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json' },
                            });
                            if (!res.ok) throw new Error('search failed');
                            return (await res.json()).results || [];
                        } catch (e) {
                            console.warn('[SAPRF] recipient search failed', e);
                            return [];
                        }
                    },

                    isSelected(id) {
                        return this.selected.some((u) => u.id === id);
                    },

                    add(user) {
                        if (!this.isSelected(user.id)) {
                            this.selected.push(user);
                            this.sync();
                        }
                        this.query = '';
                        this.results = [];
                        this.open = false;
                    },

                    remove(id) {
                        this.selected = this.selected.filter((u) => u.id !== id);
                        this.sync();
                    },

                    sync() {
                        rule.value[fieldName] = this.selected.map((u) => u.id).join(',');
                        this.$dispatch('audience-changed');
                    },
                };
            }
        </script>
    @endpush
@endonce
