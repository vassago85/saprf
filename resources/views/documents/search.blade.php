<x-layouts.public title="Search — SAPRF Documents" current="documents">
    @php
        // Kicker tone map — kickers on hits mirror the badge tones on the
        // /documents index cards so the visual thread from "browse" to
        // "search" is continuous.
        $kickerTones = [
            'Sport Rules' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            'Selection'   => 'bg-blue-50 text-blue-800 ring-blue-200',
            'Legal'       => 'bg-stone-100 text-stone-700 ring-stone-200',
            'Help'        => 'bg-amber-50 text-amber-900 ring-amber-200',
        ];
    @endphp

    <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-10">
        <div class="mb-6">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">
                SAPRF Publications
            </p>
            <div class="mt-1 flex items-baseline gap-3">
                <h1 class="text-3xl sm:text-4xl font-bold text-stone-900 dark:text-stone-100">
                    Search
                </h1>
                <a href="{{ route('documents.index') }}"
                   class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                    ← All documents
                </a>
            </div>
            <p class="mt-2 max-w-2xl text-sm text-stone-600 dark:text-stone-400">
                Search jumps straight to the right section in the right document — across all
                {{ $corpus_size }} SAPRF publications (rules, divisions, selection, legal, FAQ).
            </p>
        </div>

        <div class="mb-8">
            @include('documents._search-form', ['query' => $query])
        </div>

        @if($query === '')
            {{-- Empty state on first landing (someone bookmarked /documents/search). --}}
            <div class="rounded-xl border border-dashed border-stone-300 bg-stone-50 p-8 text-center">
                <p class="text-sm text-stone-600">Type a query above to search the SAPRF corpus.</p>
                <div class="mt-4 flex flex-wrap items-center justify-center gap-2 text-xs">
                    <span class="text-stone-500">Try:</span>
                    @foreach([
                        'pr22 provincial requirements',
                        'prs provincial requirements',
                        'ammunition',
                        'safety area',
                        'negligent discharge',
                        'code of conduct',
                    ] as $eg)
                        <a href="{{ route('documents.search', ['q' => $eg]) }}"
                           class="rounded-full bg-white ring-1 ring-inset ring-stone-200 hover:ring-emerald-300 hover:bg-emerald-50 hover:text-emerald-800 px-2.5 py-1 font-medium text-stone-700 transition">
                            {{ $eg }}
                        </a>
                    @endforeach
                </div>
            </div>
        @elseif(empty($results))
            <div class="rounded-xl border border-stone-200 bg-white p-8 text-center">
                <p class="text-sm text-stone-700">
                    No results for <span class="font-semibold">"{{ $query }}"</span>.
                </p>
                <p class="mt-2 text-xs text-stone-500">
                    Try shorter or different keywords — the search matches literal words in
                    document headings and body text (no fuzzy matching).
                </p>
            </div>
        @else
            <p class="mb-5 text-sm text-stone-600">
                {{ count($results) }} result{{ count($results) === 1 ? '' : 's' }}
                for <span class="font-semibold text-stone-900">"{{ $query }}"</span>
            </p>

            <ul class="space-y-3">
                @foreach($results as $r)
                    @php
                        $kickerClasses = $kickerTones[$r['kicker']] ?? $kickerTones['Legal'];
                    @endphp
                    <li>
                        <a href="{{ $r['doc_url'] }}#{{ $r['section_id'] }}"
                           class="group block rounded-xl border border-stone-200 bg-white p-4 sm:p-5 shadow-sm transition hover:border-emerald-500 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full ring-1 ring-inset px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $kickerClasses }}">
                                    {{ $r['kicker'] }}
                                </span>
                                <span class="text-xs font-medium text-stone-600">{{ $r['doc_title'] }}</span>
                            </div>

                            <h2 class="mt-1.5 text-base sm:text-lg font-semibold text-stone-900 group-hover:text-emerald-700">
                                {!! \App\Support\SearchHighlight::apply($r['section_heading'], $r['tokens']) !!}
                            </h2>

                            @if($r['snippet'] !== '')
                                <p class="mt-1.5 text-sm text-stone-600 leading-relaxed">
                                    {!! \App\Support\SearchHighlight::apply($r['snippet'], $r['tokens']) !!}
                                </p>
                            @endif

                            <div class="mt-3 flex items-center justify-between text-xs">
                                <span class="text-stone-400 font-mono truncate">
                                    {{ parse_url($r['doc_url'], PHP_URL_PATH) }}#{{ $r['section_id'] }}
                                </span>
                                <span class="inline-flex items-center gap-1 font-semibold text-emerald-700 group-hover:translate-x-0.5 transition-transform">
                                    Open
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-3.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                    </svg>
                                </span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.public>
