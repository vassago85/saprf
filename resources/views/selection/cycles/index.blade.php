<x-layouts.app :title="'Selection Cycles'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Selection Cycles</h1>
                <p class="mt-1 text-sm text-stone-500">Per-championship selection cycles (series + season). Every cycle runs against a versioned policy.</p>
            </div>
            @can('create', App\Models\SelectionCycle::class)
                <a href="{{ route('selection.cycles.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    New Cycle
                </a>
            @endcan
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200">
                <thead class="bg-stone-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Cycle</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Championship</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500 whitespace-nowrap">Qualifying period</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Mode</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Policy</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Athletes</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($cycles as $cycle)
                        <tr>
                            <td class="px-6 py-4 text-sm font-mono font-semibold text-stone-900 whitespace-nowrap">{{ $cycle->series }} {{ $cycle->season }}</td>
                            <td class="px-6 py-4 text-sm text-stone-700">{{ $cycle->championship_name }}</td>
                            <td class="px-6 py-4 text-sm text-stone-600 whitespace-nowrap">
                                {{ $cycle->qualifying_period_start?->format('Y-m-d') }} → {{ $cycle->qualifying_period_end?->format('Y-m-d') }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ match($cycle->status) { 'open' => 'bg-emerald-100 text-emerald-800', 'frozen' => 'bg-amber-100 text-amber-800', 'announced' => 'bg-sky-100 text-sky-800', 'closed' => 'bg-stone-100 text-stone-600', default => 'bg-stone-100 text-stone-700' } }}">{{ ucfirst($cycle->status) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                @if ($cycle->isAssumeQualified())
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-inset ring-amber-200" title="Every athlete auto-passes ELG/PART; only DEC-01 gates progression.">Assume qualified</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-200" title="Strict policy rules are evaluated for every athlete.">Strict</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-stone-600">
                                @if ($cycle->activePolicy)
                                    v{{ $cycle->activePolicy->version }}
                                @else
                                    <span class="text-stone-400">no policy imported</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right text-sm text-stone-700">{{ $cycle->athletes_count }}</td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('selection.cycles.show', $cycle) }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-900">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-sm text-stone-400">No selection cycles yet. Create one to get started.</td>
                        </tr>
                    @endforelse
                </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
