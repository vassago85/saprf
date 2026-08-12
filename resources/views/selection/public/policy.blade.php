<x-layouts.public :title="$title . ' — SAPRF'" :current="'selection-policy-' . $series_key">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">
                        {{ $series_title }} · {{ $season }} Cycle
                    </p>
                    @if($is_current)
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800">
                            Current
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-stone-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-stone-700">
                            Historical
                        </span>
                    @endif
                </div>
                <h1 class="mt-1 text-3xl font-bold text-stone-900 dark:text-stone-100">
                    {{ $title }}
                </h1>
                <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">
                    Reproduced verbatim from the SAPRF selection process document. In case of any conflict, the
                    <a class="text-blue-600 hover:underline"
                       href="https://www.precisionrifle.co.za/documents/"
                       target="_blank" rel="noopener">official SAPRF publication</a>
                    is authoritative.
                </p>
                @if(! empty($other_seasons))
                    <p class="mt-3 text-sm text-stone-600 dark:text-stone-400">
                        Other cycles:
                        @foreach($other_seasons as $s)
                            <a class="ml-1 inline-flex items-center rounded-md border border-stone-300 px-2 py-0.5 text-xs font-semibold text-stone-700 hover:bg-stone-100"
                               href="{{ route('selection.policy.public', ['series' => $series_key, 'season' => $s]) }}">
                                {{ $series_title }} {{ $s }}
                            </a>
                        @endforeach
                    </p>
                @endif
            </div>
            <div class="hidden sm:block text-right text-xs text-stone-500">
                Source file<br>
                <code class="text-[11px]">{{ $source_path }}</code>
            </div>
        </div>

        <article class="prose prose-stone dark:prose-invert max-w-none prose-headings:scroll-mt-24 prose-table:text-sm prose-th:bg-stone-100 dark:prose-th:bg-stone-800 prose-th:font-semibold prose-td:align-top">
            {!! $html !!}
        </article>
    </div>
</x-layouts.public>
