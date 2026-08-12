<x-layouts.guest>
    <x-slot:title>Terms & Conditions — SAPRF</x-slot:title>

    <x-public-nav current="documents" />

    <div class="bg-stone-50 min-h-screen">
        {{-- Header --}}
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10 sm:py-14">
                <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">SAPRF · Legal</p>
                <h1 class="mt-1 font-heading text-3xl sm:text-4xl font-bold text-stone-900 tracking-tight">
                    Terms &amp; Conditions
                </h1>
                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-stone-500">
                    @if ($last_updated)
                        <span>Last updated <span class="font-medium text-stone-700">{{ $last_updated->format('j F Y') }}</span></span>
                        <span class="text-stone-300">·</span>
                    @endif
                    <span>Liability cap <span class="font-mono text-stone-700">{{ $liability_cap }}</span></span>
                    <span class="text-stone-300">·</span>
                    <a href="{{ route('legal.privacy') }}" class="text-emerald-700 hover:text-emerald-800 underline underline-offset-2">Privacy Policy</a>
                </div>
                <p class="mt-5 max-w-3xl text-sm text-stone-600">
                    Reproduced verbatim from the SAPRF-supplied Terms &amp; Conditions. In case of any conflict between this page and a signed copy of the SAPRF T&amp;Cs, the signed copy prevails.
                </p>
            </div>
        </div>

        {{-- Body: sidebar TOC + article --}}
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10">
            <div class="lg:grid lg:grid-cols-[16rem_1fr] lg:gap-12">
                {{-- TOC (sticky on desktop, collapses to <details> on mobile) --}}
                @if(! empty($toc))
                    <aside class="mb-8 lg:mb-0">
                        <div class="lg:sticky lg:top-24">
                            <details class="lg:hidden rounded-lg border border-stone-200 bg-white p-3" open>
                                <summary class="cursor-pointer text-sm font-semibold text-stone-800">
                                    On this page ({{ count($toc) }} sections)
                                </summary>
                                <nav class="mt-3 space-y-1 text-sm">
                                    @foreach($toc as $item)
                                        <a href="#{{ $item['id'] }}" class="block text-stone-600 hover:text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1">
                                            {{ $item['text'] }}
                                        </a>
                                    @endforeach
                                </nav>
                            </details>

                            <div class="hidden lg:block">
                                <p class="text-xs font-semibold uppercase tracking-wider text-stone-500 mb-3">
                                    On this page
                                </p>
                                <nav class="space-y-1 text-sm border-l border-stone-200">
                                    @foreach($toc as $item)
                                        <a href="#{{ $item['id'] }}"
                                           class="block -ml-px border-l-2 border-transparent pl-4 py-1.5 text-stone-600 hover:text-emerald-700 hover:border-emerald-500">
                                            {{ $item['text'] }}
                                        </a>
                                    @endforeach
                                </nav>
                            </div>
                        </div>
                    </aside>
                @endif

                {{-- Article --}}
                <div class="min-w-0">
                    <article class="prose prose-stone max-w-none
                        prose-headings:font-heading prose-headings:tracking-tight prose-headings:text-stone-900
                        prose-h2:scroll-mt-24 prose-h2:mt-12 prose-h2:pb-2 prose-h2:border-b prose-h2:border-stone-200
                        prose-h3:mt-8
                        prose-p:text-stone-700 prose-p:leading-relaxed
                        prose-a:text-emerald-700 prose-a:no-underline hover:prose-a:underline
                        prose-strong:text-stone-900
                        prose-ul:my-4 prose-li:my-1 prose-li:marker:text-stone-400
                        prose-table:text-sm prose-table:w-full prose-table:border-collapse
                        prose-th:bg-stone-100 prose-th:text-stone-900 prose-th:font-semibold prose-th:text-left prose-th:p-3 prose-th:border prose-th:border-stone-200
                        prose-td:p-3 prose-td:border prose-td:border-stone-200 prose-td:align-top
                        prose-code:text-emerald-800 prose-code:bg-emerald-50 prose-code:px-1 prose-code:rounded prose-code:before:content-none prose-code:after:content-none">
                        {!! $html !!}
                    </article>

                    <div class="mt-12 pt-6 border-t border-stone-200 flex flex-wrap items-center justify-between gap-3 text-xs text-stone-500">
                        <span>
                            Source file: <code class="font-mono text-stone-600">{{ $source_path }}</code>
                        </span>
                        <a href="{{ route('documents.index') }}" class="text-emerald-700 hover:text-emerald-800 font-semibold">
                            ← Back to Documents
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-public-footer />
</x-layouts.guest>
