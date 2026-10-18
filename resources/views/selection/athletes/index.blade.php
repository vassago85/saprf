<x-layouts.app :title="'Athletes · '.$cycle->series.' '.$cycle->season">
    <div class="space-y-6">
        <x-page-header :title="$cycle->series . ' ' . $cycle->season . ' · Athletes'" subtitle="Every shooter registered for selection this cycle.">
            <x-slot:breadcrumb>Athlete registry</x-slot:breadcrumb>
            @can('create', App\Models\SelectionAthlete::class)
                <x-slot:actions>
                    <form method="POST" action="{{ route('selection.cycles.athletes.bulk-register', $cycle) }}">
                        @csrf
                        <button type="submit" onclick="return confirm('Bulk-register every shooter with a qualifying score?')" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Bulk-register from scores</button>
                    </form>
                    <a href="{{ route('selection.cycles.athletes.create', $cycle) }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Register athlete</a>
                </x-slot:actions>
            @endcan
        </x-page-header>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <x-filter-bar :action="route('selection.cycles.athletes.index', $cycle)" label="Athlete filters">
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
        </x-filter-bar>

        <x-admin-table>
                <table class="min-w-full divide-y divide-stone-200">
                <thead class="bg-stone-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Shooter</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Division</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">State</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Criteria met</th>
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
                            <td class="px-6 py-3 text-sm">
                                @php($p = $progress[$a->id] ?? ['met' => 0, 'total' => 0, 'pct' => 0])
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-24 overflow-hidden rounded-full bg-stone-100">
                                        <div class="h-full rounded-full {{ $p['pct'] === 100 ? 'bg-emerald-500' : ($p['pct'] >= 50 ? 'bg-amber-500' : 'bg-red-400') }}" style="width: {{ $p['pct'] }}%"></div>
                                    </div>
                                    <span class="text-xs font-medium text-stone-600">{{ $p['met'] }}/{{ $p['total'] }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-3 text-sm text-stone-600">{{ $a->last_evaluated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="px-6 py-3 text-right"><a href="{{ route('selection.cycles.athletes.show', [$cycle, $a]) }}" class="text-sm text-emerald-700 hover:text-emerald-900">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-stone-400">No athletes match the current filters.</td></tr>
                    @endforelse
                </tbody>
                </table>
        </x-admin-table>
        <div>{{ $athletes->links() }}</div>
    </div>
</x-layouts.app>
