@props([
    'title',
    'blurb',
    'html',
    'toc' => [],
    'lastUpdated' => null,
    'sourcePath' => null,
    'kicker' => 'SAPRF · Legal',
    'subtitle' => null,          // e.g. "South African Practical Precision Rifle Federation (NPC)"
    'version' => null,           // e.g. "v2.0"
    'effectiveDate' => null,     // e.g. "2 November 2025" — display date shown next to the version pill
    'status' => null,            // ['label' => 'Current', 'tone' => 'emerald'] — mirrors DocumentsController::catalog()
    'currentDocRoute' => null,   // route name for active-nav highlighting: 'documents' | 'faq' | 'selection-policy-pr22' etc.
    'description' => null,
])

@php
    // Only worth rendering a sticky sidebar when there's meaningful navigation
    // to be had. Documents like /privacy have 25 shallow H2s that make a great
    // sidebar; /code-of-conduct has 13. Below the threshold (say, terms with
    // only 6 flat sections) the sidebar becomes visual noise so we hide it
    // and let the article breathe across the full width.
    $showToc = is_array($toc) && count($toc) > 6;

    // Status pill tones. Matches the tone tokens used on the /documents index
    // cards so the pill on the doc header looks like an extension of the card
    // the reader just clicked.
    $statusTones = [
        'emerald'  => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'stone'    => 'bg-stone-100 text-stone-700 ring-stone-200',
        'sapphire' => 'bg-blue-50 text-blue-800 ring-blue-200',
        'amber'    => 'bg-amber-50 text-amber-900 ring-amber-200',
        'red'      => 'bg-red-50 text-red-800 ring-red-200',
    ];
    $statusClasses = $status && isset($statusTones[$status['tone']])
        ? $statusTones[$status['tone']]
        : $statusTones['stone'];
@endphp

{{-- ─────────────────────────────────────────────────────────────────────────
     Shared chrome for verbatim SAPRF legal / governance pages.

     Layout at lg: and up:
       ┌─────────────────────────────────────────────────────┐
       │ [progress bar]                                       │
       │ ─────────── header (title, pills, print) ─────────── │
       │ ┌────── sticky ToC ──────┐ ┌── article (68ch) ──┐    │
       │ │  filter box            │ │  clauses with       │    │
       │ │  section list (H2)     │ │  hanging-indent     │    │
       │ │  scroll-spy highlight  │ │  gutter numbers     │    │
       │ └────────────────────────┘ └─────────────────────┘    │
       │            [back-to-top button, floating]             │
       └─────────────────────────────────────────────────────┘

     Below lg: the ToC collapses into a <details> pinned under the header.
     All interactive behaviour is handled by a single Alpine component
     (`legalDoc`) registered on the wrapper — no external JS bundle. --}}

<x-layouts.guest :description="$description ?? $subtitle ?? $blurb">
    <x-slot:title>{{ $title }} — SAPRF</x-slot:title>

    <x-public-nav :current="$currentDocRoute ?? 'documents'" />

    <div class="bg-stone-50 min-h-screen"
         x-data="legalDoc()"
         x-init="init()"
         @keydown.window.meta.k.prevent="focusSearch()"
         @keydown.window.ctrl.k.prevent="focusSearch()">

        {{-- Reading-progress bar. Sits directly under the 64px sticky nav so
             it visually belongs to the page, not the site chrome. --}}
        <div class="legal-doc-progress fixed left-0 right-0 top-16 h-0.5 bg-stone-200 z-40" aria-hidden="true">
            <div class="h-full bg-emerald-600 transition-[width] duration-100 ease-out"
                 :style="`width: ${progress}%`"></div>
        </div>

        {{-- Header block -------------------------------------------------- --}}
        <div class="legal-doc-chrome bg-white border-b border-stone-200">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-12">
                <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">{{ $kicker }}</p>
                <h1 class="mt-1 font-heading text-3xl sm:text-4xl font-bold text-stone-900 tracking-tight">
                    {{ $title }}
                </h1>
                @if($subtitle)
                    <p class="mt-2 text-sm sm:text-base text-stone-600 max-w-3xl">{{ $subtitle }}</p>
                @endif

                {{-- Metadata pills + action buttons.
                     Pills carry authoritative document metadata (status,
                     version, effective date, file-mtime "last updated").
                     The Print button uses window.print(), which the
                     browser's own "Save as PDF" dialog exposes on both
                     desktop and mobile. --}}
                <div class="mt-5 flex flex-wrap items-center gap-2">
                    @if($status)
                        <span class="inline-flex items-center gap-1.5 rounded-full ring-1 ring-inset px-2.5 py-1 text-xs font-semibold uppercase tracking-wide {{ $statusClasses }}">
                            {{ $status['label'] }}
                        </span>
                    @endif
                    @if($version)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200 px-2.5 py-1 text-xs font-semibold">
                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M2.5 4A1.5 1.5 0 0 1 4 2.5h7.879a1.5 1.5 0 0 1 1.06.44l4.122 4.12a1.5 1.5 0 0 1 .439 1.061V16A1.5 1.5 0 0 1 16 17.5H4A1.5 1.5 0 0 1 2.5 16V4Zm3 3a.5.5 0 0 0 0 1h4a.5.5 0 0 0 0-1h-4Zm0 3a.5.5 0 0 0 0 1h9a.5.5 0 0 0 0-1h-9Zm0 3a.5.5 0 0 0 0 1h9a.5.5 0 0 0 0-1h-9Z" clip-rule="evenodd"/></svg>
                            Version {{ $version }}
                        </span>
                    @endif
                    @if($effectiveDate)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-stone-100 text-stone-700 ring-1 ring-inset ring-stone-200 px-2.5 py-1 text-xs font-semibold">
                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.75A2 2 0 0 1 17.5 6v9A2 2 0 0 1 15.5 17h-11A2 2 0 0 1 2.5 15V6a2 2 0 0 1 2-2H5V2.75A.75.75 0 0 1 5.75 2ZM4 9h12v6a.5.5 0 0 1-.5.5h-11A.5.5 0 0 1 4 15V9Z" clip-rule="evenodd"/></svg>
                            Effective {{ $effectiveDate }}
                        </span>
                    @endif
                    @if($lastUpdated && ! $effectiveDate)
                        {{-- Fall back to file-mtime only when no authoritative date was given. --}}
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-stone-100 text-stone-700 ring-1 ring-inset ring-stone-200 px-2.5 py-1 text-xs font-semibold">
                            Last updated {{ $lastUpdated->format('j M Y') }}
                        </span>
                    @endif
                    {{ $meta ?? '' }}

                    <div class="grow"></div>

                    <button type="button"
                            @click="window.print()"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 hover:border-stone-400 transition shadow-sm">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5 2.75A.75.75 0 0 1 5.75 2h8.5a.75.75 0 0 1 .75.75V6H5V2.75Zm11.5 4.75h-13A1.5 1.5 0 0 0 2 9v4.5A1.5 1.5 0 0 0 3.5 15H5v2.25c0 .414.336.75.75.75h8.5a.75.75 0 0 0 .75-.75V15h1.5A1.5 1.5 0 0 0 18 13.5V9a1.5 1.5 0 0 0-1.5-1.5ZM6.5 13.5h7v3h-7v-3Z" clip-rule="evenodd"/></svg>
                        Print / Save as PDF
                    </button>
                </div>

                @if(trim($blurb) !== '')
                    <p class="mt-5 max-w-3xl text-sm text-stone-600 leading-relaxed">{{ $blurb }}</p>
                @endif
            </div>
        </div>

        {{-- Main body: sticky ToC on the left, article on the right.
             When the doc has 6-or-fewer H2 sections ($showToc is false) we
             collapse to a single centred column — a sidebar with 2-3 links
             adds visual noise without helping navigation. --}}
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <div @class([
                'lg:grid lg:grid-cols-[17rem_minmax(0,1fr)] lg:gap-10 xl:gap-14' => $showToc,
                'mx-auto max-w-3xl' => ! $showToc,
            ])>

                {{-- Table of contents ------------------------------------ --}}
                @if($showToc)
                    <aside class="legal-doc-toc mb-8 lg:mb-0">
                        {{-- Mobile: collapsible details, closed by default so
                             it doesn't push the first section off-screen. --}}
                        <details class="lg:hidden rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                            <summary class="cursor-pointer list-none px-4 py-3 flex items-center justify-between text-sm font-semibold text-stone-800 hover:bg-stone-50">
                                <span>Jump to section ({{ count($toc) }})</span>
                                <svg class="size-4 text-stone-400 transition-transform" style="transition-duration: 200ms;" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.06l3.71-3.83a.75.75 0 1 1 1.08 1.04l-4.25 4.39a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                            </summary>
                            <nav aria-label="Table of contents" class="border-t border-stone-100 max-h-[60vh] overflow-y-auto">
                                @foreach($toc as $item)
                                    <a href="#{{ $item['id'] }}"
                                       @click="closeMobileToc($event)"
                                       class="block px-4 py-2 text-sm text-stone-700 hover:bg-emerald-50 hover:text-emerald-800 border-t border-stone-100 first:border-t-0">
                                        {{ $item['text'] }}
                                    </a>
                                @endforeach
                            </nav>
                        </details>

                        {{-- Desktop: sticky panel with search + scroll-spy. --}}
                        <div class="hidden lg:block lg:sticky lg:top-24">
                            <div class="mb-3">
                                <label for="toc-search" class="sr-only">Filter sections</label>
                                <div class="relative">
                                    <svg class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 size-4 text-stone-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.428 9.804l3.634 3.634a.75.75 0 1 0 1.06-1.06l-3.634-3.635A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd"/></svg>
                                    <input id="toc-search"
                                           type="search"
                                           x-ref="search"
                                           x-model="search"
                                           placeholder="Filter sections… (⌘K)"
                                           class="w-full rounded-lg border border-stone-200 bg-white pl-8 pr-2.5 py-1.5 text-sm text-stone-800 placeholder:text-stone-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                            </div>
                            <p class="text-[0.7rem] font-semibold uppercase tracking-wider text-stone-500 px-1 mb-1">
                                On this page
                            </p>
                            <nav aria-label="Table of contents"
                                 class="max-h-[calc(100vh-11rem)] overflow-y-auto pr-1 border-l border-stone-200">
                                @foreach($toc as $item)
                                    <div x-show="matches('{{ addslashes(strtolower($item['text'])) }}')">
                                        <a href="#{{ $item['id'] }}"
                                           class="block -ml-px border-l-2 pl-3 pr-2 py-1.5 text-sm leading-snug transition-colors"
                                           :class="active === '{{ $item['id'] }}'
                                               ? 'border-emerald-600 text-emerald-800 bg-emerald-50 font-medium'
                                               : 'border-transparent text-stone-600 hover:text-emerald-700 hover:border-emerald-300'">
                                            {{ $item['text'] }}
                                        </a>
                                        @if(! empty($item['children']))
                                            <div class="ml-3 mt-0.5 mb-1 space-y-0.5">
                                                @foreach($item['children'] as $child)
                                                    <a href="#{{ $child['id'] }}"
                                                       class="block -ml-px border-l pl-3 pr-2 py-1 text-xs leading-snug transition-colors"
                                                       :class="active === '{{ $child['id'] }}'
                                                           ? 'border-emerald-500 text-emerald-700 font-medium'
                                                           : 'border-stone-100 text-stone-500 hover:text-emerald-700 hover:border-emerald-300'">
                                                        {{ $child['text'] }}
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                                <p x-show="!hasAnyMatches" x-cloak class="px-3 py-4 text-xs text-stone-400 italic">
                                    No sections match "<span x-text="search"></span>".
                                </p>
                            </nav>
                        </div>
                    </aside>
                @endif

                {{-- Article ---------------------------------------------- --}}
                <div class="min-w-0">
                    <article class="legal-doc" x-ref="article">
                        {!! $html !!}
                    </article>

                    <div class="mt-12 pt-6 border-t border-stone-200 flex flex-wrap items-center justify-between gap-3 text-xs text-stone-500">
                        @if($sourcePath)
                            <span>Source file: <code class="font-mono text-stone-600">{{ $sourcePath }}</code></span>
                        @else
                            <span></span>
                        @endif
                        <a href="{{ route('documents.index') }}" class="text-emerald-700 hover:text-emerald-800 font-semibold">
                            ← Back to Documents
                        </a>
                    </div>
                </div>
            </div>
        </div>

        {{-- Floating back-to-top button. Only appears after the reader has
             scrolled past the fold; a keyboard-focusable button, not just a
             decorative arrow. --}}
        <button type="button"
                x-show="showBackToTop"
                x-cloak
                x-transition.opacity
                @click="scrollTop()"
                class="legal-doc-back-to-top fixed bottom-6 right-6 z-40 inline-flex items-center gap-1.5 rounded-full bg-stone-900 text-white px-4 py-2.5 text-sm font-medium shadow-lg hover:bg-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9.47 4.72a.75.75 0 0 1 1.06 0l5 5a.75.75 0 0 1-1.06 1.06L10.75 7.06V16.25a.75.75 0 0 1-1.5 0V7.06L4.53 10.78a.75.75 0 1 1-1.06-1.06l5-5Z" clip-rule="evenodd"/></svg>
            Top
        </button>
    </div>

    <x-public-footer />

    {{-- Alpine component powering the legal-doc chrome:
           progress   — reading-progress bar (0-100), tracked on window scroll.
           active     — id of the H2/H3 currently in view, via IntersectionObserver.
           showBackToTop — reveal the fixed button after the reader scrolls > 600px.
           search / matches / hasAnyMatches — ToC client-filter.
           focusSearch / scrollTop / closeMobileToc — small imperative actions
             called from event handlers on the template.

         Register via alpine:init so the definition is present the moment
         Alpine boots (after @fluxScripts), avoiding a load-order race. --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('legalDoc', () => ({
                progress: 0,
                active: '',
                showBackToTop: false,
                search: '',
                _matchCount: 0,

                init() {
                    // Scroll → progress bar + back-to-top visibility. Passive
                    // listener so we never block scroll perf on long docs.
                    const updateScroll = () => {
                        const scrollTop = window.scrollY || document.documentElement.scrollTop;
                        const docH = document.documentElement.scrollHeight - window.innerHeight;
                        this.progress = docH > 0 ? Math.min(100, Math.max(0, (scrollTop / docH) * 100)) : 0;
                        this.showBackToTop = scrollTop > 600;
                    };
                    updateScroll();
                    window.addEventListener('scroll', updateScroll, { passive: true });

                    // Scroll-spy over the H2 + H3 headings in the article.
                    // rootMargin pulls the trigger line down under the fixed
                    // nav so a heading is "active" from the moment it lands
                    // just below the header, not when it's already off-screen.
                    const article = this.$refs.article;
                    if (!article || typeof IntersectionObserver === 'undefined') return;
                    const headings = article.querySelectorAll('h2[id], h3[id]');
                    if (!headings.length) return;

                    const visible = new Map();
                    const observer = new IntersectionObserver((entries) => {
                        for (const entry of entries) {
                            if (entry.isIntersecting) {
                                visible.set(entry.target.id, entry.target.getBoundingClientRect().top);
                            } else {
                                visible.delete(entry.target.id);
                            }
                        }
                        if (visible.size === 0) return;
                        // Pick the visible heading nearest the top of the viewport.
                        let bestId = '', bestTop = Number.POSITIVE_INFINITY;
                        for (const [id, top] of visible) {
                            if (top < bestTop) { bestTop = top; bestId = id; }
                        }
                        this.active = bestId;
                    }, {
                        rootMargin: '-96px 0px -70% 0px',
                        threshold: [0, 1],
                    });
                    headings.forEach(h => observer.observe(h));
                },

                matches(haystack) {
                    if (!this.search) return true;
                    return haystack.includes(this.search.toLowerCase().trim());
                },
                get hasAnyMatches() {
                    if (!this.search) return true;
                    // Re-count matches from the DOM on demand. Cheaper than
                    // reactively tracking every ToC entry, and this only runs
                    // when the user has typed something.
                    const q = this.search.toLowerCase().trim();
                    return Array.from(document.querySelectorAll('.legal-doc-toc a[href^="#"]'))
                        .some(a => a.textContent.toLowerCase().includes(q));
                },

                focusSearch() {
                    if (this.$refs.search) {
                        this.$refs.search.focus();
                        this.$refs.search.select();
                    }
                },
                scrollTop() {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                },
                closeMobileToc(e) {
                    // Collapse the mobile <details> after a jump so the
                    // reader lands on the section instead of the ToC.
                    const details = e.target.closest('details');
                    if (details) details.open = false;
                },
            }));
        });
    </script>
</x-layouts.guest>
