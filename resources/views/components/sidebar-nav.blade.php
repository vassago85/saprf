@php
    $navUser = auth()->user();
    $viewMode = $navUser?->effectiveViewMode() ?? \App\Navigation\SidebarMenu::CONTEXT_SHOOTER;
    $sections = $navUser
        ? \App\Navigation\SidebarMenu::sectionsFor($navUser, $viewMode)
        : [];

    // Initial open/closed state, consumed by Alpine. Localstorage overrides
    // this for *expandable* groups unless the active group needs forcing open.
    $expandedState = [];
    foreach ($sections as $section) {
        $expandedState[$section['key']] = (bool) $section['expanded'];
    }
    $activeKey = collect($sections)->firstWhere('has_current', true)['key'] ?? null;

    // Flat list for the Ctrl/Cmd+K dialog.
    $searchItems = $navUser
        ? \App\Navigation\SidebarMenu::searchItemsFor($navUser, $viewMode)
        : [];
@endphp

<div
    class="sidebar-nav-root"
    x-data="sidebarNav({
        viewMode: @js($viewMode),
        initial: @js($expandedState),
        activeKey: @js($activeKey),
        searchItems: @js($searchItems),
    })"
    x-init="init()"
    @keydown.window.prevent.ctrl.k="openSearch()"
    @keydown.window.prevent.meta.k="openSearch()"
>
    {{-- Small nav search affordance. Phase 1 filters visible items; Phase 2
         will fold shooter / match / club search in (admin only). --}}
    <div class="px-2 pb-2 pt-1">
        <button type="button"
                @click="openSearch()"
                class="group flex w-full items-center gap-2 rounded-md border border-stone-200 bg-stone-50 px-2 py-1.5 text-left text-xs text-stone-500 transition hover:border-stone-300 hover:bg-white hover:text-stone-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-3.5 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.3-4.3m2.3-5.2a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"/>
            </svg>
            <span class="sidebar-search-label flex-1 truncate">Search</span>
            <kbd class="sidebar-search-kbd hidden rounded border border-stone-300 bg-white px-1 text-[10px] font-semibold text-stone-500 group-hover:border-stone-400 sm:inline">⌘K</kbd>
        </button>
    </div>

    <nav class="sidebar-nav-list px-1" aria-label="Main navigation">
        @php $previousSuperGroup = null; @endphp
        @foreach($sections as $section)
            @if(!empty($section['super_group']) && $section['super_group'] !== $previousSuperGroup)
                <div class="sidebar-super-heading px-3 pt-4 pb-1 text-[10px] font-semibold uppercase tracking-wider text-stone-400 leading-none">
                    {{ $section['super_group'] }}
                </div>
            @endif
            @php $previousSuperGroup = $section['super_group'] ?? null; @endphp

            <x-sidebar-nav-section :section="$section">
                @foreach($section['items'] as $item)
                    <x-sidebar-nav-item :item="$item" />
                @endforeach
            </x-sidebar-nav-section>
        @endforeach
    </nav>

    {{-- Ctrl/Cmd+K search dialog. Filters visible nav items only in Phase 1. --}}
    <div x-show="searchOpen"
         x-cloak
         x-transition.opacity
         @click.self="closeSearch()"
         @keydown.escape.window="closeSearch()"
         class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 pt-24"
         role="dialog" aria-modal="true" aria-label="Navigation search">
        <div class="w-full max-w-lg overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-stone-200">
            <div class="flex items-center gap-2 border-b border-stone-200 px-4 py-3">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-4 shrink-0 text-stone-400">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.3-4.3m2.3-5.2a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"/>
                </svg>
                <input type="text"
                       x-model="searchQuery"
                       x-ref="searchInput"
                       @keydown.enter.prevent="goToFirst()"
                       @keydown.down.prevent="moveSelection(1)"
                       @keydown.up.prevent="moveSelection(-1)"
                       placeholder="Jump to…"
                       class="w-full border-0 bg-transparent text-sm text-stone-800 placeholder-stone-400 focus:outline-none focus:ring-0" />
                <kbd class="rounded border border-stone-200 bg-stone-50 px-1 text-[10px] font-semibold text-stone-500">ESC</kbd>
            </div>
            <ul class="max-h-80 overflow-y-auto py-1" x-ref="searchResults">
                <template x-for="(result, idx) in filteredResults()" :key="result.id">
                    <li>
                        <a :href="result.href"
                           @click="closeSearch()"
                           :class="idx === selectedIndex ? 'bg-emerald-50 text-emerald-800' : 'text-stone-700 hover:bg-stone-100'"
                           class="flex items-center justify-between gap-2 px-4 py-2 text-sm">
                            <span class="truncate" x-text="result.label"></span>
                            <span class="shrink-0 text-xs text-stone-400" x-text="result.section"></span>
                        </a>
                    </li>
                </template>
                <li x-show="filteredResults().length === 0" class="px-4 py-6 text-center text-sm text-stone-500">
                    No matches.
                </li>
            </ul>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('sidebarNav', ({ viewMode, initial, activeKey, searchItems }) => ({
            viewMode,
            activeKey,
            expanded: {},
            searchItems,
            searchOpen: false,
            searchQuery: '',
            selectedIndex: 0,

            init() {
                // Load persisted state, then overlay the server's expanded flag
                // so the active group always wins over whatever the user had
                // collapsed last session.
                const persisted = this.loadPersisted();
                for (const key of Object.keys(initial)) {
                    this.expanded[key] = key in persisted ? persisted[key] : initial[key];
                }
                if (this.activeKey) {
                    this.expanded[this.activeKey] = true;
                }

                this.$nextTick(() => this.scrollActiveIntoView());
            },

            loadPersisted() {
                try {
                    // v2: groups default collapsed; bumping the key resets any
                    // pre-v2 "everything open" preferences so the new default
                    // actually shows up for returning users.
                    const raw = localStorage.getItem(`saprf.sidebarGroups.v2.${this.viewMode}`);
                    return raw ? JSON.parse(raw) : {};
                } catch (e) {
                    return {};
                }
            },

            persist() {
                try {
                    localStorage.setItem(
                        `saprf.sidebarGroups.v2.${this.viewMode}`,
                        JSON.stringify(this.expanded),
                    );
                } catch (e) {}
            },

            toggle(key) {
                this.expanded[key] = !this.expanded[key];
                this.persist();
            },

            isOpen(key) {
                return !!this.expanded[key];
            },

            scrollActiveIntoView() {
                const el = this.$el.querySelector('[data-nav-current="true"]');
                if (el && typeof el.scrollIntoView === 'function') {
                    el.scrollIntoView({ block: 'nearest' });
                }
            },

            openSearch() {
                this.searchOpen = true;
                this.searchQuery = '';
                this.selectedIndex = 0;
                this.$nextTick(() => this.$refs.searchInput?.focus());
            },

            closeSearch() {
                this.searchOpen = false;
            },

            filteredResults() {
                const q = this.searchQuery.trim().toLowerCase();
                if (q === '') return this.searchItems;
                return this.searchItems.filter((item) =>
                    item.label.toLowerCase().includes(q)
                    || (item.section ?? '').toLowerCase().includes(q),
                );
            },

            moveSelection(delta) {
                const results = this.filteredResults();
                if (results.length === 0) return;
                this.selectedIndex = (this.selectedIndex + delta + results.length) % results.length;
            },

            goToFirst() {
                const results = this.filteredResults();
                const target = results[this.selectedIndex] ?? results[0];
                if (target) window.location.assign(target.href);
            },
        }));
    });
</script>
@endpush
@endonce
