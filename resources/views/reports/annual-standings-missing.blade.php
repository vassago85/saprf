<x-layouts.app :title="'Annual standings report'">
    <div class="space-y-6">
        <x-page-header
            title="Annual standings report"
            subtitle="Top 5 per division with counted nationals and the SA Championships contribution."
        />

        <div class="rounded-2xl border border-stone-200 bg-white p-6">
            <p class="text-sm text-stone-700">
                No {{ $series }} championship is set up for {{ $season }} yet, so there is no
                annual report to render. The report is built off the SA Championships (final)
                match for a series and season.
            </p>

            <form method="GET" action="{{ route('reports.annual-standings') }}" class="mt-4 flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500">Series</label>
                    <select name="series" class="mt-1 rounded-md border-stone-300 text-sm">
                        <option value="PRS" @selected($series === 'PRS')>PRS (Centrefire)</option>
                        <option value="PR22" @selected($series === 'PR22')>PR22 (Rimfire)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-stone-500">Season</label>
                    <select name="season" class="mt-1 rounded-md border-stone-300 text-sm">
                        @forelse($seasons as $available)
                            <option value="{{ $available }}" @selected((string) $available === $season)>{{ $available }}</option>
                        @empty
                            <option value="{{ $season }}" selected>{{ $season }}</option>
                        @endforelse
                    </select>
                </div>
                <button type="submit" class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                    Open report
                </button>
            </form>

            <p class="mt-4 text-xs text-stone-500">
                In the meantime, the season leaderboard is on the
                <a href="{{ route('standings.prs') }}?season={{ $season }}" class="font-semibold text-emerald-700 hover:underline">
                    {{ $series }} standings page</a>.
            </p>
        </div>
    </div>
</x-layouts.app>
