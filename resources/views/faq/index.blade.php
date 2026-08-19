<x-layouts.guest description="Answers to common SAPRF questions — membership, match entry, divisions, SAPRF numbers, clubs, and how Precision Rifle scoring works.">
    <x-slot:title>Frequently Asked Questions — SAPRF</x-slot:title>

    <x-public-nav current="faq" />

    <div class="bg-stone-50 min-h-screen">
        {{-- Page header --}}
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 py-10 sm:py-14">
                <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">SAPRF · Help</p>
                <h1 class="mt-1 font-heading text-3xl sm:text-4xl font-bold text-stone-900 tracking-tight">
                    Frequently Asked Questions
                </h1>
                @if(trim($intro) !== '')
                    <div class="mt-4 max-w-3xl text-sm sm:text-base text-stone-600 leading-relaxed
                                [&_p]:mt-2 [&_p:first-child]:mt-0">
                        {!! $intro !!}
                    </div>
                @endif
                <div class="mt-5 flex flex-wrap items-center gap-3 text-xs text-stone-500">
                    <span>{{ count($items) }} questions</span>
                    @if($last_updated)
                        <span aria-hidden="true">·</span>
                        <span>Last updated <span class="font-medium text-stone-700">{{ $last_updated->format('j F Y') }}</span></span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Question index — quick jump list, one line per question --}}
        @if(count($items) > 0)
            <div class="max-w-4xl mx-auto px-4 sm:px-6 pt-8">
                <nav aria-label="Jump to question"
                     class="rounded-xl border border-stone-200 bg-white p-4 sm:p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wider text-stone-500 mb-3">On this page</p>
                    <ol class="grid gap-1.5 sm:grid-cols-2 text-sm list-decimal list-inside marker:text-stone-400">
                        @foreach($items as $item)
                            <li class="text-stone-700">
                                <a href="#{{ $item['anchor'] }}"
                                   class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $item['question'] }}</a>
                            </li>
                        @endforeach
                    </ol>
                </nav>
            </div>
        @endif

        {{-- Accordion of questions.
             <details> is used deliberately over a JS accordion so:
              • JS is not required to expand/collapse (progressive enhancement),
              • deep-links (#anchor) auto-open the target question via CSS :target,
              • printing the page still shows all answers. --}}
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 sm:py-10 space-y-3">
            @forelse($items as $index => $item)
                <details id="{{ $item['anchor'] }}"
                         @if($index === 0) open @endif
                         class="group rounded-xl border border-stone-200 bg-white shadow-sm
                                open:shadow-md transition-shadow
                                target:ring-2 target:ring-emerald-500 target:ring-offset-2 target:ring-offset-stone-50 scroll-mt-24">
                    <summary class="cursor-pointer list-none px-5 sm:px-6 py-4 sm:py-5
                                    flex items-start gap-4
                                    rounded-xl
                                    hover:bg-stone-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                        <span aria-hidden="true"
                              class="mt-1 shrink-0 inline-flex items-center justify-center size-6 rounded-full
                                     bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200
                                     text-xs font-semibold leading-none tabular-nums">
                            {{ $index + 1 }}
                        </span>
                        <span class="flex-1 font-heading text-lg sm:text-xl font-semibold text-stone-900 tracking-tight leading-snug">
                            {{ $item['question'] }}
                        </span>
                        <svg aria-hidden="true"
                             class="mt-1 shrink-0 size-5 text-stone-400 transition-transform group-open:rotate-180"
                             viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.06l3.71-3.83a.75.75 0 1 1 1.08 1.04l-4.25 4.39a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/>
                        </svg>
                    </summary>

                    <div class="px-5 sm:px-6 pb-5 sm:pb-6 -mt-1 border-t border-stone-100 pt-4
                                prose prose-stone max-w-none prose-sm sm:prose-base
                                prose-p:text-stone-700 prose-p:leading-relaxed
                                prose-a:text-emerald-700 prose-a:no-underline hover:prose-a:underline
                                prose-strong:text-stone-900
                                prose-ul:my-3 prose-li:my-1 prose-li:marker:text-stone-400
                                prose-ol:my-3">
                        {!! $item['html'] !!}
                    </div>
                </details>
            @empty
                <div class="rounded-xl border border-dashed border-stone-300 bg-white p-8 text-center text-sm text-stone-500">
                    No FAQ entries have been published yet.
                </div>
            @endforelse
        </div>

        {{-- Footer helper: still stuck? contact us --}}
        <div class="max-w-4xl mx-auto px-4 sm:px-6 pb-12">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 sm:p-6
                        flex flex-col sm:flex-row items-start sm:items-center gap-4 sm:gap-6">
                <div class="flex-1">
                    <h2 class="font-heading text-lg font-semibold text-emerald-900 tracking-tight">Still have a question?</h2>
                    <p class="mt-1 text-sm text-emerald-800">
                        If your question isn't answered here, get in touch and we'll come back to you.
                    </p>
                </div>
                <a href="{{ route('contact.create') }}"
                   class="shrink-0 inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition shadow-sm">
                    Send us a message
                    <svg class="size-4" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                </a>
            </div>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 text-xs text-stone-500">
                @if($source_path)
                    <span>Source file: <code class="font-mono text-stone-600">{{ $source_path }}</code></span>
                @else
                    <span></span>
                @endif
                <a href="{{ route('documents.index') }}" class="text-emerald-700 hover:text-emerald-800 font-semibold">
                    ← Back to Documents
                </a>
            </div>
        </div>
    </div>

    <x-public-footer />
</x-layouts.guest>
