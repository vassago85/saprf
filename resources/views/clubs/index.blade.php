<x-layouts.app :title="'Shooting Clubs'">
    <div class="space-y-6">
        <x-page-header title="Clubs" subtitle="Master list of shooting clubs used across the platform. SAPRF-recognised clubs count toward IPRF selection eligibility (ELG-03 / ELG-05).">
            <x-slot:actions>
                @can('create', App\Models\Club::class)
                    <a href="{{ route('clubs.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        New Club
                    </a>
                @endcan
            </x-slot:actions>
        </x-page-header>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        <x-filter-bar :action="route('clubs.index')" label="Club filters" class="mt-0 grid grid-cols-1 md:grid-cols-5 gap-3">
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
                <select name="active" id="active" class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All</option>
                    <option value="active" @selected($filters['active'] === 'active')>Active</option>
                    <option value="inactive" @selected($filters['active'] === 'inactive')>Inactive</option>
                </select>
            </div>
        </x-filter-bar>

        <x-admin-table class="mt-0">
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
                                <x-row-actions>
                                    <x-row-actions-menu>
                                        @can('update', $club)
                                            <a href="{{ route('clubs.edit', $club) }}" class="block px-3 py-1.5 text-left text-stone-700 hover:bg-stone-50" role="menuitem">Edit</a>
                                        @endcan
                                        @can('merge', $club)
                                            <a href="{{ route('clubs.merge-form', $club) }}" class="block px-3 py-1.5 text-left text-stone-700 hover:bg-stone-50" role="menuitem">Merge into…</a>
                                        @endcan
                                        @can('update', $club)
                                            <form method="POST" action="{{ route('clubs.toggle-recognition', $club) }}">
                                                @csrf
                                                <button type="submit" class="block w-full px-3 py-1.5 text-left text-stone-700 hover:bg-stone-50" role="menuitem">
                                                    {{ $club->saprf_recognised ? 'Remove recognition' : 'Recognise club' }}
                                                </button>
                                            </form>
                                        @endcan
                                    </x-row-actions-menu>
                                </x-row-actions>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-stone-400">No clubs match your filters.</td>
                        </tr>
                    @endforelse
                </tbody>
                </table>
        </x-admin-table>

        <div>{{ $clubs->links() }}</div>
    </div>
</x-layouts.app>
