<x-layouts.public title="Documents — SAPRF" current="documents">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-10">
        <div class="mb-10">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">
                SAPRF Publications
            </p>
            <h1 class="mt-1 text-3xl sm:text-4xl font-bold text-stone-900 dark:text-stone-100">
                Documents
            </h1>
            <p class="mt-3 max-w-3xl text-sm sm:text-base text-stone-600 dark:text-stone-400">
                The federation's published policies, selection processes and governing terms.
                Everything here is public — no login required. Documents are reproduced verbatim
                from their authoritative source files.
            </p>
        </div>

        @foreach($categories as $category)
            <section class="mb-12">
                <div class="mb-5 border-l-4 border-emerald-600 pl-4">
                    <h2 class="text-xl font-semibold text-stone-900 dark:text-stone-100">
                        {{ $category['heading'] }}
                    </h2>
                    <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">
                        {{ $category['blurb'] }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach($category['items'] as $item)
                        <a href="{{ $item['url'] }}"
                           class="group block rounded-xl border border-stone-200 dark:border-stone-800 bg-white dark:bg-stone-900 p-5 shadow-sm transition hover:border-emerald-500 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="text-base font-semibold text-stone-900 dark:text-stone-100 group-hover:text-emerald-700 dark:group-hover:text-emerald-400">
                                        {{ $item['title'] }}
                                    </h3>
                                    @if(! empty($item['subtitle']))
                                        <p class="mt-0.5 text-xs font-medium uppercase tracking-wide text-stone-500">
                                            {{ $item['subtitle'] }}
                                        </p>
                                    @endif
                                </div>
                                @if(! empty($item['badge']))
                                    @php($tone = $item['badge']['tone'])
                                    <span @class([
                                        'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                        'bg-emerald-100 text-emerald-800' => $tone === 'emerald',
                                        'bg-stone-200 text-stone-700' => $tone === 'stone',
                                        'bg-amber-100 text-amber-800' => $tone === 'amber',
                                    ])>
                                        {{ $item['badge']['label'] }}
                                    </span>
                                @endif
                            </div>

                            <p class="mt-3 text-sm text-stone-600 dark:text-stone-400">
                                {{ $item['description'] }}
                            </p>

                            <div class="mt-4 flex items-center justify-between text-xs text-stone-500">
                                <span>
                                    @if(! empty($item['last_updated']))
                                        Updated {{ $item['last_updated']->format('d M Y') }}
                                    @else
                                        &nbsp;
                                    @endif
                                </span>
                                <span class="inline-flex items-center gap-1 font-semibold text-emerald-700 dark:text-emerald-400 group-hover:translate-x-0.5 transition-transform">
                                    Read
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-3.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                    </svg>
                                </span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="mt-8 rounded-lg border border-stone-200 dark:border-stone-800 bg-stone-50 dark:bg-stone-900/50 p-5 text-sm text-stone-600 dark:text-stone-400">
            Missing a document you were expecting? The federation publishes selection cycles, policy
            revisions and governance updates here as they're ratified. If you can't find what you're
            looking for, <a href="{{ route('contact.create') }}" class="font-semibold text-emerald-700 dark:text-emerald-400 hover:underline">get in touch</a>.
        </div>
    </div>
</x-layouts.public>
