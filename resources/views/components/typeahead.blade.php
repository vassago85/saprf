@props([
    'name',
    'label',
    'searchUrl',
    'createUrl' => null,
    'placeholder' => 'Start typing to search...',
    'displayField' => 'name',
    'subtextField' => null,
    'initialId' => null,
    'initialText' => null,
    'required' => false,
    'dependsOn' => null,
    'dependsParam' => 'make_id',
    'createPayloadExtra' => null,
])

<div
    x-data="{
        query: '{{ $initialText ?? '' }}',
        selectedId: '{{ $initialId ?? '' }}',
        results: [],
        open: false,
        loading: false,
        creating: false,
        debounce: null,
        dependsValue: '{{ $dependsOn ? '' : 'none' }}',

        init() {
            if (this.selectedId && this.query) {
                this.open = false;
            }
            @if($dependsOn)
                this.$watch('dependsValue', (val) => {
                    this.query = '';
                    this.selectedId = '';
                    this.results = [];
                });
            @endif
        },

        async search() {
            clearTimeout(this.debounce);
            this.selectedId = '';
            if (this.query.length < 1) {
                this.results = [];
                this.open = false;
                return;
            }
            this.debounce = setTimeout(async () => {
                this.loading = true;
                let url = '{{ $searchUrl }}' + '?q=' + encodeURIComponent(this.query);
                @if($dependsOn)
                    if (this.dependsValue) {
                        url += '&{{ $dependsParam }}=' + this.dependsValue;
                    }
                @endif
                try {
                    const res = await fetch(url);
                    const json = await res.json();
                    this.results = json.data || [];
                    this.open = true;
                } catch (e) {
                    this.results = [];
                }
                this.loading = false;
            }, 250);
        },

        select(item) {
            this.selectedId = item.id;
            this.query = item.{{ $displayField }};
            this.open = false;
            this.results = [];
            this.$dispatch('typeahead-selected', { name: '{{ $name }}', id: item.id, item: item });
        },

        async createNew() {
            if (!this.query.trim() || this.creating) return;
            this.creating = true;
            try {
                const csrfToken = document.querySelector('meta[name=csrf-token]')?.content;
                let payload = { name: this.query.trim() };
                @if($createPayloadExtra)
                    if (this.dependsValue && this.dependsValue !== 'none') {
                        payload['{{ $dependsParam }}'] = this.dependsValue;
                    }
                @endif
                const res = await fetch('{{ $createUrl }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(payload),
                });
                const json = await res.json();
                if (json.data) {
                    this.select(json.data);
                }
            } catch (e) {
                console.error('Failed to create:', e);
            }
            this.creating = false;
        },

        get hasExactMatch() {
            return this.results.some(r => r.{{ $displayField }}.toLowerCase() === this.query.toLowerCase());
        },

        // Keep the visible input's native validity in sync with whether the
        // user has actually *selected* a suggestion. Typing text without
        // picking one would otherwise pass required-validation silently.
        syncValidity(el) {
            if (! el) return;
            @if($required)
                if (this.query.trim() && ! this.selectedId) {
                    el.setCustomValidity('Please pick a suggestion from the list, or use &quot;Add ...&quot; to create it.');
                } else {
                    el.setCustomValidity('');
                }
            @endif
        }
    }"
    @if($dependsOn)
        x-on:typeahead-selected.window="if ($event.detail.name === '{{ $dependsOn }}') { dependsValue = $event.detail.id; }"
    @endif
    @click.outside="open = false"
    class="relative"
>
    <label for="{{ $name }}_input" class="block text-sm font-medium text-stone-700 mb-1">{{ $label }}</label>

    <input type="hidden" name="{{ $name }}" :value="selectedId">

    <div class="relative">
        <input
            type="text"
            id="{{ $name }}_input"
            x-ref="input"
            x-model="query"
            x-effect="syncValidity($refs.input)"
            @input="search()"
            @focus="if (query.length >= 1 && results.length > 0) open = true"
            @keydown.escape="open = false"
            @keydown.arrow-down.prevent="$refs.list?.querySelector('[role=option]')?.focus()"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            @if($required && !$dependsOn) required @endif
            @if($dependsOn) :disabled="!dependsValue" @endif
            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-stone-50 disabled:text-stone-400"
        >

        <div class="absolute inset-y-0 right-0 flex items-center pr-2.5 pointer-events-none">
            <template x-if="loading">
                <svg class="animate-spin h-4 w-4 text-stone-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </template>
            <template x-if="!loading && selectedId">
                <svg class="h-4 w-4 text-emerald-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
            </template>
            <template x-if="!loading && !selectedId && query.length > 0">
                <svg class="h-4 w-4 text-stone-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
            </template>
        </div>
    </div>

    <div
        x-show="open"
        x-transition.opacity.duration.150ms
        x-ref="list"
        class="absolute z-50 mt-1 w-full rounded-lg border border-stone-200 bg-white shadow-lg max-h-60 overflow-y-auto"
    >
        <template x-if="!selectedId && results.length === 0 && !loading && query.length >= 1">
            <div class="px-3 py-2 text-sm text-stone-400">No matches found</div>
        </template>

        <template x-for="item in results" :key="item.id">
            <button
                type="button"
                role="option"
                @click="select(item)"
                @keydown.enter.prevent="select(item)"
                @keydown.arrow-down.prevent="$el.nextElementSibling?.focus()"
                @keydown.arrow-up.prevent="$el.previousElementSibling?.focus()"
                class="w-full text-left px-3 py-2 text-sm hover:bg-emerald-50 focus:bg-emerald-50 focus:outline-none flex items-center justify-between gap-2 cursor-pointer"
            >
                <span x-text="item.{{ $displayField }}" class="font-medium text-stone-800"></span>
                @if($subtextField)
                    <span x-text="item.{{ $subtextField }}" class="text-xs text-stone-400 shrink-0"></span>
                @endif
            </button>
        </template>

        @if($createUrl)
            <template x-if="!selectedId && query.length >= 2 && !hasExactMatch && !loading">
                <button
                    type="button"
                    @click="createNew()"
                    :disabled="creating"
                    class="w-full text-left px-3 py-2.5 text-sm border-t border-stone-100 bg-stone-50 hover:bg-emerald-50 focus:bg-emerald-50 focus:outline-none flex items-center gap-2 cursor-pointer font-medium text-emerald-700"
                >
                    <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    <span>Add "<span x-text="query.trim()"></span>"</span>
                    <template x-if="creating">
                        <svg class="animate-spin h-3 w-3 ml-auto text-emerald-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </template>
                </button>
            </template>
        @endif
    </div>
</div>
