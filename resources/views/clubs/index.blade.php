<x-layouts.app :title="'Shooting Clubs'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Shooting Clubs</h1>
                <p class="mt-1 text-sm text-stone-500">Master list of shooting clubs used across the platform. SAPRF-recognised clubs count toward IPRF selection eligibility (ELG-03 / ELG-05).</p>
            </div>
            @can('create', App\Models\Club::class)
                <a href="{{ route('clubs.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    New Club
                </a>
            @endcan
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <form method="GET" action="{{ route('clubs.index') }}" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm grid grid-cols-1 md:grid-cols-5 gap-3">
            <div class="md:col-span-2">
                <label for="q" class="block text-xs font-medium text-stone-500 mb-1">Search by name or abbreviation</label>
                <input type="search" name="q" id="q" value="{{ $filters['search'] }}" placeholder="e.g. Pretoria or PPRC" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div>
                <label for="province_id" class="block text-xs font-medium text-stone-500 mb-1">Province</label>
                <select name="province_id" id="province_id" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All</option>
                    @foreach ($provinces as $p)
                        <option value="{{ $p->id }}" @selected((string) $filters['provinceId'] === (string) $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="recognition" class="block text-xs font-medium text-stone-500 mb-1">Recognition</label>
                <select name="recognition" id="recognition" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All</option>
                    <option value="recognised" @selected($filters['recognition'] === 'recognised')>SAPRF-recognised</option>
                    <option value="unrecognised" @selected($filters['recognition'] === 'unrecognised')>Not recognised</option>
                </select>
            </div>
            <div>
                <label for="active" class="block text-xs font-medium text-stone-500 mb-1">Status</label>
                <div class="flex gap-2">
                    <select name="active" id="active" class="flex-1 rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All</option>
                        <option value="active" @selected($filters['active'] === 'active')>Active</option>
                        <option value="inactive" @selected($filters['active'] === 'inactive')>Inactive</option>
                    </select>
                    <button type="submit" class="rounded-lg bg-stone-900 px-3 py-2 text-sm font-medium text-white hover:bg-stone-800">Go</button>
                </div>
            </div>
        </form>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <table class="min-w-full divide-y divide-stone-200">
                <thead class="bg-stone-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Province</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Recognition</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Active</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Members</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($clubs as $club)
                        <tr>
                            <td class="px-6 py-4 text-sm">
                                <div class="font-semibold text-stone-900">{{ $club->name }}</div>
                                @if ($club->abbreviation)
                                    <div class="text-xs text-stone-500 font-mono">{{ $club->abbreviation }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-stone-600">{{ $club->province?->name ?? '—' }}</td>
                            <td class="px-6 py-4 text-sm">
                                @if ($club->saprf_recognised)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-200">SAPRF-recognised</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-300">Not recognised</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm">
                                @if ($club->is_active)
                                    <span class="text-emerald-700 font-medium">Yes</span>
                                @else
                                    <span class="text-stone-400">No</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right text-sm text-stone-700">{{ $club->users_count }}</td>
                            <td class="px-6 py-4 text-right text-sm">
                                <div class="flex items-center justify-end gap-3">
                                    @can('update', $club)
                                        <form method="POST" action="{{ route('clubs.toggle-recognition', $club) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-stone-500 hover:text-stone-800" title="Toggle SAPRF recognition">
                                                {{ $club->saprf_recognised ? 'Un-recognise' : 'Recognise' }}
                                            </button>
                                        </form>
                                    @endcan
                                    @can('merge', $club)
                                        <a href="{{ route('clubs.merge-form', $club) }}" class="text-amber-700 hover:text-amber-900">Merge</a>
                                    @endcan
                                    @can('update', $club)
                                        <a href="{{ route('clubs.edit', $club) }}" class="text-emerald-700 font-medium hover:text-emerald-900">Edit</a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-stone-400">No clubs match your filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $clubs->links() }}</div>
    </div>
</x-layouts.app>
