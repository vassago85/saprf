<x-layouts.app>
    <x-slot:title>Pending Approvals - SAPRF</x-slot:title>

    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Pending Approvals</h1>
                <p class="text-sm text-stone-500 mt-1">Review and approve user-submitted entries before they become visible.</p>
            </div>
            @if($totalPending > 0)
                <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold bg-amber-100 text-amber-800">
                    {{ $totalPending }} pending
                </span>
            @endif
        </div>

        @if($totalPending === 0)
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-12 text-center">
                <svg class="mx-auto size-12 text-emerald-400 mb-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <h3 class="text-lg font-semibold text-stone-900">All clear</h3>
                <p class="text-sm text-stone-500 mt-1">No items waiting for approval.</p>
            </div>
        @endif

        {{-- Venues --}}
        @if($pendingVenues->count())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-stone-200 bg-stone-50 px-6 py-4">
                    <h2 class="font-heading text-lg font-bold text-stone-900">
                        Venues
                        <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">{{ $pendingVenues->count() }}</span>
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-stone-200">
                                <th class="px-6 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">City</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">Province</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">Submitted By</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-stone-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($pendingVenues as $venue)
                                <tr>
                                    <td class="px-6 py-4 font-medium text-stone-900">{{ $venue->name }}</td>
                                    <td class="px-6 py-4 text-stone-500">{{ $venue->city ?? '—' }}</td>
                                    <td class="px-6 py-4 text-stone-500">{{ $venue->province?->name ?? '—' }}</td>
                                    <td class="px-6 py-4 text-stone-500">{{ $venue->submitter?->name ?? '—' }}</td>
                                    <td class="px-6 py-4 text-stone-400">{{ $venue->created_at->format('d M Y') }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <form method="POST" action="{{ route('approvals.approve', ['type' => 'venue', 'id' => $venue->id]) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 transition">Approve</button>
                                            </form>
                                            <form method="POST" action="{{ route('approvals.reject', ['type' => 'venue', 'id' => $venue->id]) }}" onsubmit="return confirm('Remove this venue?')">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center rounded-lg bg-red-50 border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100 transition">Reject</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Firearm Makes --}}
        @if($pendingFirearmMakes->count())
            @include('admin.approvals._reference-table', [
                'title' => 'Firearm Makes',
                'items' => $pendingFirearmMakes,
                'type' => 'firearm-make',
                'columns' => ['name' => 'Name', 'country' => 'Country'],
            ])
        @endif

        {{-- Firearm Models --}}
        @if($pendingFirearmModels->count())
            @include('admin.approvals._reference-table', [
                'title' => 'Firearm Models',
                'items' => $pendingFirearmModels,
                'type' => 'firearm-model',
                'columns' => ['name' => 'Name'],
                'parentColumn' => 'make.name',
                'parentLabel' => 'Make',
            ])
        @endif

        {{-- Firearm Calibres --}}
        @if($pendingFirearmCalibres->count())
            @include('admin.approvals._reference-table', [
                'title' => 'Firearm Calibres',
                'items' => $pendingFirearmCalibres,
                'type' => 'firearm-calibre',
                'columns' => ['name' => 'Name', 'category' => 'Category'],
            ])
        @endif

        {{-- Optic Makes --}}
        @if($pendingOpticMakes->count())
            @include('admin.approvals._reference-table', [
                'title' => 'Optic Makes',
                'items' => $pendingOpticMakes,
                'type' => 'optic-make',
                'columns' => ['name' => 'Name', 'country' => 'Country'],
            ])
        @endif

        {{-- Optic Models --}}
        @if($pendingOpticModels->count())
            @include('admin.approvals._reference-table', [
                'title' => 'Optic Models',
                'items' => $pendingOpticModels,
                'type' => 'optic-model',
                'columns' => ['name' => 'Name'],
                'parentColumn' => 'make.name',
                'parentLabel' => 'Make',
            ])
        @endif
    </div>
</x-layouts.app>
