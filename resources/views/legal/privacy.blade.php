<x-layouts.guest>
    <x-slot:title>Privacy Policy — SAPRF</x-slot:title>

    <x-public-nav />

    <div class="bg-stone-50 min-h-screen">
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 py-10 sm:py-14">
                <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Legal</p>
                <h1 class="mt-1 font-heading text-3xl sm:text-4xl font-bold text-stone-900 tracking-tight">Privacy Policy</h1>
                @if ($last_updated)
                    <p class="mt-2 text-sm text-stone-500">Last updated: {{ $last_updated->format('j F Y') }}</p>
                @endif
                <p class="mt-3 text-sm text-stone-600">
                    Reproduced verbatim from the SAPRF-supplied Privacy Policy. See also our
                    <a href="{{ route('legal.terms') }}" class="text-emerald-700 hover:text-emerald-800 underline">Terms &amp; Conditions</a>.
                </p>
            </div>
        </div>

        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-10">
            <article class="prose prose-stone max-w-none prose-headings:font-heading prose-headings:tracking-tight prose-a:text-emerald-700 prose-table:text-sm prose-th:bg-stone-100 prose-th:font-semibold prose-td:align-top">
                {!! $html !!}
            </article>

            <div class="mt-10 text-xs text-stone-400">
                Source: <code class="font-mono">{{ $source_path }}</code>
            </div>
        </div>
    </div>

    <x-public-footer />
</x-layouts.guest>
