{{-- Shared search form. Reused by both /documents (index) and
     /documents/search (results). GET so results are bookmarkable and
     the browser's back button behaves; no JS required. --}}
@props(['query' => '', 'compact' => false])

<form action="{{ route('documents.search') }}" method="GET" role="search" class="w-full">
    <label for="doc-search{{ $compact ? '-compact' : '' }}" class="sr-only">
        Search SAPRF documents
    </label>
    <div class="relative">
        <svg xmlns="http://www.w3.org/2000/svg"
             class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 size-5 text-stone-400"
             viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.428 9.804l3.634 3.634a.75.75 0 1 0 1.06-1.06l-3.634-3.635A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd"/>
        </svg>
        <input id="doc-search{{ $compact ? '-compact' : '' }}"
               name="q"
               type="search"
               value="{{ $query }}"
               autocomplete="off"
               autocapitalize="off"
               spellcheck="false"
               placeholder="Search all SAPRF documents — e.g. provincial requirements, ammunition, safety area…"
               maxlength="200"
               class="w-full rounded-xl border border-stone-200 bg-white pl-10 pr-24 py-3 text-sm text-stone-900 placeholder:text-stone-400 shadow-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
        <button type="submit"
                class="absolute right-1.5 top-1/2 -translate-y-1/2 inline-flex items-center gap-1 rounded-lg bg-emerald-700 text-white px-3.5 py-1.5 text-sm font-semibold hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:ring-emerald-500 transition shadow-sm">
            Search
        </button>
    </div>
</form>
