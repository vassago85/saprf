{{--
    SAPRF custom Tailwind pagination view.

    Overrides Laravel's shipped `pagination::tailwind` to:
    1. Push the compact Previous / Page X of Y / Next layout up through
       the `lg` breakpoint (1024px). The default switches to the full
       numeric pager at `sm` (640px), which overflows every tablet-sized
       admin screen — the audit log at 718px wide was showing
       "1 2 3 4 5 6 7 8 9 10 … 901 902" squeezed into a card and
       spilling off the edge.
    2. Use the site's emerald / stone palette (previous view defaulted
       to gray / blue), drop the unused `dark:` variants, and bump the
       hit-target size slightly so taps land cleanly on mobile.
    3. Keep the full numeric pager intact from `lg:` up, where screen
       width can actually accommodate 12 buttons + "Showing X of Y".

    This single file governs every paginated index on the site —
    audit logs, user management, financials, memberships, events, etc.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}">

        {{-- Compact variant: shown below `lg` (1024px) --}}
        <div class="flex items-center justify-between gap-2 lg:hidden">

            @if ($paginator->onFirstPage())
                <span class="inline-flex items-center px-4 py-2.5 text-sm font-medium text-stone-400 bg-white border border-stone-200 cursor-not-allowed leading-5 rounded-lg">
                    {!! __('pagination.previous') !!}
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                   class="inline-flex items-center px-4 py-2.5 text-sm font-semibold text-stone-700 bg-white border border-stone-300 leading-5 rounded-lg hover:bg-stone-50 hover:text-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 active:bg-stone-100 transition">
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            <span class="text-xs text-stone-500 text-center flex-1">
                {!! __('Page') !!}
                <span class="font-semibold text-stone-900">{{ $paginator->currentPage() }}</span>
                {!! __('of') !!}
                <span class="font-semibold text-stone-900">{{ $paginator->lastPage() }}</span>
            </span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                   class="inline-flex items-center px-4 py-2.5 text-sm font-semibold text-stone-700 bg-white border border-stone-300 leading-5 rounded-lg hover:bg-stone-50 hover:text-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 active:bg-stone-100 transition">
                    {!! __('pagination.next') !!}
                </a>
            @else
                <span class="inline-flex items-center px-4 py-2.5 text-sm font-medium text-stone-400 bg-white border border-stone-200 cursor-not-allowed leading-5 rounded-lg">
                    {!! __('pagination.next') !!}
                </span>
            @endif
        </div>

        {{-- Full numeric variant: shown at `lg:` and up (1024px+) --}}
        <div class="hidden lg:flex lg:flex-1 lg:items-center lg:justify-between lg:gap-2">

            <div>
                <p class="text-sm text-stone-600 leading-5">
                    {!! __('Showing') !!}
                    @if ($paginator->firstItem())
                        <span class="font-semibold text-stone-900">{{ $paginator->firstItem() }}</span>
                        {!! __('to') !!}
                        <span class="font-semibold text-stone-900">{{ $paginator->lastItem() }}</span>
                    @else
                        {{ $paginator->count() }}
                    @endif
                    {!! __('of') !!}
                    <span class="font-semibold text-stone-900">{{ $paginator->total() }}</span>
                    {!! __('results') !!}
                </p>
            </div>

            <div>
                <span class="inline-flex rtl:flex-row-reverse shadow-sm rounded-lg">

                    {{-- Previous Page Link --}}
                    @if ($paginator->onFirstPage())
                        <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                            <span class="inline-flex items-center px-2.5 py-2 text-sm font-medium text-stone-300 bg-white border border-stone-200 cursor-not-allowed rounded-l-lg leading-5" aria-hidden="true">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
                           class="inline-flex items-center px-2.5 py-2 text-sm font-medium text-stone-500 bg-white border border-stone-300 rounded-l-lg leading-5 hover:bg-stone-50 hover:text-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 active:bg-stone-100 transition"
                           aria-label="{{ __('pagination.previous') }}">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    @endif

                    {{-- Pagination Elements --}}
                    @foreach ($elements as $element)
                        {{-- "Three Dots" Separator --}}
                        @if (is_string($element))
                            <span aria-disabled="true">
                                <span class="inline-flex items-center px-4 py-2 -ml-px text-sm font-medium text-stone-600 bg-white border border-stone-300 cursor-default leading-5">{{ $element }}</span>
                            </span>
                        @endif

                        {{-- Array Of Links --}}
                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page">
                                        <span class="inline-flex items-center px-4 py-2 -ml-px text-sm font-semibold text-emerald-800 bg-emerald-50 border border-emerald-300 cursor-default leading-5">{{ $page }}</span>
                                    </span>
                                @else
                                    <a href="{{ $url }}"
                                       class="inline-flex items-center px-4 py-2 -ml-px text-sm font-medium text-stone-600 bg-white border border-stone-300 leading-5 hover:bg-stone-50 hover:text-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 active:bg-stone-100 transition"
                                       aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                        {{ $page }}
                                    </a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next Page Link --}}
                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" rel="next"
                           class="inline-flex items-center px-2.5 py-2 -ml-px text-sm font-medium text-stone-500 bg-white border border-stone-300 rounded-r-lg leading-5 hover:bg-stone-50 hover:text-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 active:bg-stone-100 transition"
                           aria-label="{{ __('pagination.next') }}">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    @else
                        <span aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                            <span class="inline-flex items-center px-2.5 py-2 -ml-px text-sm font-medium text-stone-300 bg-white border border-stone-200 cursor-not-allowed rounded-r-lg leading-5" aria-hidden="true">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </span>
                    @endif
                </span>
            </div>
        </div>
    </nav>
@endif
