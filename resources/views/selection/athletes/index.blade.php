<x-layouts.app :title="'Athletes · '.$cycle->series.' '.$cycle->season">
    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-stone-400">Athlete registry</div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $cycle->series }} {{ $cycle->season }} · Athletes</h1>
                <p class="text-sm text-stone-500">Every shooter registered for selection this cycle.</p>
            </div>
            <div class="flex items-center gap-2">
                @can('create', App\Models\SelectionAthlete::class)
                    <form method="POST" action="{{ route('selection.cycles.athletes.bulk-register', $cycle) }}">
                        @csrf
                        <button type="submit" onclick="return confirm('Bulk-register every shooter with a qualifying score?')" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Bulk-register from scores</button>
                    </form>
                    <a href="{{ route('selection.cycles.athletes.create', $cycle) }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Register athlete</a>
                @endcan
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-widest text-stone-400 mb-1">State</label>
                <select name="state" class="rounded-lg border border-stone-300 text-sm">
                    <option value="">All</option>
                    @foreach (\App\Models\SelectionAthlete::STATES as $s)
                        <option value="{{ $s }}" @selected($state === $s)>{{ str_replace('_', ' ', $s) }} ({{ $stateCounts[$s] ?? 0 }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-widest text-stone-400 mb-1">Division</label>
                <select name="division_id" class="rounded-lg border border-stone-300 text-sm">
                    <option value="">All</option>
                    @foreach ($divisions as $d)
                        <option value="{{ $d->id }}" @selected((int) $divisionId === $d->id)>{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-lg bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-900">Filter</button>
        </form>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <table class="min-w-full divide-y divide-stone-200">
                <thead class="bg-stone-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Shooter</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Division</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">State</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Last evaluated</th>
                        <th class="px-6 py-3 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($athletes as $a)
                        <tr>
                            <td class="px-6 py-3 text-sm font-medium text-stone-900">{{ $a->user?->name }}<br><span class="text-xs text-stone-500">{{ $a->user?->email }}</span></td>
                            <td class="px-6 py-3 text-sm text-stone-700">{{ $a->claimedDivision?->name ?? '—' }}</td>
                            <td class="px-6 py-3 text-sm"><span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-700">{{ str_replace('_', ' ', $a->state) }}</span></td>
                            <td class="px-6 py-3 text-sm text-stone-600">{{ $a->last_evaluated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="px-6 py-3 text-right"><a href="{{ route('selection.cycles.athletes.show', [$cycle, $a]) }}" class="text-sm text-emerald-700 hover:text-emerald-900">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-stone-400">No athletes match the current filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $athletes->links() }}</div>
    </div>
</x-layouts.app>
