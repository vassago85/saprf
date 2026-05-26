<x-layouts.app :title="'Selection Report - SAPRF'">
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <a href="{{ route('reports.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; All Reports</a>
                <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Selection Report</h1>
                <p class="mt-1 text-sm text-stone-500">Qualified shooters with rank, season points, and out-of-province requirement status.</p>
            </div>
            <a href="{{ route('reports.selection.export', request()->only(['series', 'season'])) }}"
               class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                Export CSV
            </a>
        </div>

        {{-- Filters --}}
        <form method="GET" class="rounded-xl border border-stone-200 bg-white shadow-sm p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Series</label>
                    <div class="flex rounded-lg bg-stone-50 border border-stone-200 p-1 w-fit">
                        <a href="{{ url()->current() }}?series=PRS&season={{ $season }}"
                           class="px-4 py-1.5 rounded text-sm font-semibold transition {{ $series === 'PRS' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:bg-stone-100' }}">PRS</a>
                        <a href="{{ url()->current() }}?series=PR22&season={{ $season }}"
                           class="px-4 py-1.5 rounded text-sm font-semibold transition {{ $series === 'PR22' ? 'bg-sky-600 text-white shadow-sm' : 'text-stone-600 hover:bg-stone-100' }}">PR22</a>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Season</label>
                    <select name="season" onchange="this.form.submit()"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        @foreach($seasons as $s)
                            <option value="{{ $s }}" @selected((string) $season === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="series" value="{{ $series }}">
                </div>
            </div>
        </form>

        {{-- KPIs --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                <p class="text-xs font-medium text-emerald-700 uppercase">Qualified Shooters</p>
                <p class="mt-1 text-2xl font-bold text-emerald-900">{{ $qualifiedCount }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Total Active Shooters</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">{{ $rows->count() }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Active Memberships</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">{{ $rows->where('membership_active', true)->count() }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Series &middot; Season</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">{{ $series }} &middot; {{ $season }}</p>
            </div>
        </div>

        {{-- Table --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50">
                        <tr class="text-left text-xs uppercase text-stone-500">
                            <th class="px-5 py-3 w-16">Rank</th>
                            <th class="px-5 py-3">Shooter</th>
                            <th class="px-5 py-3">Member #</th>
                            <th class="px-5 py-3">Province</th>
                            <th class="px-5 py-3">Membership</th>
                            <th class="px-5 py-3 text-center">Out-of-Prov</th>
                            <th class="px-5 py-3 text-right">Points</th>
                            <th class="px-5 py-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse($rows as $row)
                            <tr class="hover:bg-stone-50 {{ $row['qualified'] ? '' : 'opacity-75' }}">
                                <td class="px-5 py-3 font-mono text-stone-900">
                                    @if($row['rank'])
                                        <span class="font-bold">{{ $row['rank'] }}</span>
                                    @else
                                        <span class="text-stone-300">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 font-medium text-stone-900">{{ $row['user']->name }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-stone-600">{{ $row['saprf_number'] ?? '—' }}</td>
                                <td class="px-5 py-3 text-stone-600">{{ $row['province'] ?? '—' }}</td>
                                <td class="px-5 py-3">
                                    @if($row['membership_active'])
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Active</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-500">Inactive</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-center text-stone-600">
                                    <span class="font-mono">{{ $row['completed'] }} / {{ $row['required'] }}</span>
                                </td>
                                <td class="px-5 py-3 text-right font-mono font-semibold text-stone-900">
                                    {{ $row['points'] !== null ? number_format((float) $row['points'], 2) : '—' }}
                                </td>
                                <td class="px-5 py-3 text-center">
                                    @if($row['qualified'])
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                            Qualified
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-500">Not yet</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-12 text-center text-sm text-stone-400">No shooters with valid scores for this series and season yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
