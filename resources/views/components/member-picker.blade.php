@props([
    'name' => 'user_id',
    'label' => 'Member',
    'required' => true,
])

@php
    $oldId = old($name);
    $initial = null;
    if ($oldId) {
        $picked = \App\Models\User::query()->with('membership:id,user_id,saprf_number')->find($oldId);
        if ($picked) {
            $number = $picked->membership?->saprf_number;
            $initial = [
                'id' => $picked->id,
                'label' => $number ? $picked->name.' ('.$number.')' : $picked->name,
            ];
        }
    }
@endphp

<div x-data="memberPicker(@js(['searchUrl' => route('members.search'), 'initial' => $initial, 'required' => $required]))">
    <label class="block text-sm font-medium text-stone-700">{{ $label }} @if($required)<span class="text-red-500">*</span>@endif</label>
    <input type="hidden" name="{{ $name }}" x-bind:value="selectedId">

    <div class="relative mt-1" x-show="!selectedId" x-cloak>
        <input type="search"
            x-model="query"
            @input.debounce.250ms="search()"
            @focus="open = results.length > 0"
            @keydown.escape="open = false"
            placeholder="Search by name or number"
            autocomplete="off"
            class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
        <div x-show="open && results.length > 0" x-cloak
            class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-stone-200 bg-white shadow-lg">
            <template x-for="person in results" :key="person.id">
                <button type="button" @click="pick(person)"
                    class="block w-full px-3 py-2 text-left text-sm text-stone-900 hover:bg-emerald-50"
                    x-text="person.label"></button>
            </template>
        </div>
        <p x-show="query.trim().length >= 2 && !loading && results.length === 0" x-cloak class="mt-1 text-xs text-stone-500">No members matched that name or number.</p>
    </div>

    <div x-show="selectedId" x-cloak class="mt-1 flex items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
        <span class="text-sm font-medium text-stone-900" x-text="selectedLabel"></span>
        <button type="button" @click="clear()" class="text-xs font-semibold text-emerald-800 hover:text-emerald-950">Change</button>
    </div>
    @error($name)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>

@once
    @push('scripts')
        <script>
            function memberPicker(config) {
                return {
                    query: '',
                    results: [],
                    open: false,
                    loading: false,
                    selectedId: config.initial?.id ?? '',
                    selectedLabel: config.initial?.label ?? '',
                    async search() {
                        const term = this.query.trim();
                        if (term.length < 2) {
                            this.results = [];
                            this.open = false;
                            return;
                        }
                        this.loading = true;
                        try {
                            const res = await fetch(config.searchUrl + '?q=' + encodeURIComponent(term), {
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json' },
                            });
                            const data = res.ok ? await res.json() : { results: [] };
                            this.results = data.results || [];
                            this.open = true;
                        } catch (e) {
                            this.results = [];
                        } finally {
                            this.loading = false;
                        }
                    },
                    pick(person) {
                        this.selectedId = person.id;
                        this.selectedLabel = person.label;
                        this.query = '';
                        this.results = [];
                        this.open = false;
                    },
                    clear() {
                        this.selectedId = '';
                        this.selectedLabel = '';
                    },
                };
            }
        </script>
    @endpush
@endonce
