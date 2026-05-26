<x-layouts.app :title="'Venues - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Venues</h1>
                <p class="mt-1 text-sm text-stone-500">Manage shooting venues and ranges.</p>
            </div>
            <a href="{{ route('venues.create') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Venue
            </a>
        </div>

        <form method="GET" action="{{ route('venues.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-stone-500 mb-1">Search</label>
                <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Name, city, address..."
                       class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-stone-500 mb-1">Province</label>
                <select name="province_id" class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All Provinces</option>
                    @foreach ($provinces as $prov)
                        <option value="{{ $prov->id }}" @selected(($provinceFilter ?? '') == $prov->id)>{{ $prov->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-lg bg-stone-100 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">Filter</button>
            @if($search || $provinceFilter)
                <a href="{{ route('venues.index') }}" class="text-sm text-stone-500 hover:text-stone-700 py-2">Clear</a>
            @endif
        </form>

        @if(session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b-2 border-stone-200 bg-stone-50">
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Name</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">City</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Province</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Contact</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                            <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($venues as $venue)
                            <tr class="hover:bg-stone-50 transition-colors">
                                <td class="px-5 py-3.5 text-sm">
                                    <p class="font-medium text-stone-900">{{ $venue->name }}</p>
                                    @if($venue->address_line_1)
                                        <p class="text-xs text-stone-400 mt-0.5">{{ $venue->address_line_1 }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $venue->city ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $venue->province?->name ?? '—' }}</td>
                                <td class="px-5 py-3.5 text-sm text-stone-500">
                                    @if($venue->contact_name)
                                        <p>{{ $venue->contact_name }}</p>
                                    @endif
                                    @if($venue->contact_phone)
                                        <p class="text-xs">{{ $venue->contact_phone }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                                    @if(! $venue->is_approved)
                                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending Approval</span>
                                    @elseif($venue->is_active)
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-500 ring-1 ring-inset ring-stone-400/20">Inactive</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm">
                                    @role('developer|owner|admin')
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('venues.edit', $venue) }}" class="rounded-lg p-1.5 text-stone-400 hover:bg-stone-100 hover:text-stone-700" title="Edit">
                                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                        </a>
                                        <form method="POST" action="{{ route('venues.destroy', $venue) }}" class="inline"
                                              onsubmit="return confirm('Delete venue {{ addslashes($venue->name) }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded-lg p-1.5 text-stone-400 hover:bg-red-50 hover:text-red-600" title="Delete">
                                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                            </button>
                                        </form>
                                    </div>
                                    @else
                                    <span class="text-xs text-stone-400" title="Match directors can submit new venues. Edits require admin approval.">View only</span>
                                    @endrole
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center text-sm text-stone-400">No venues found. Add your first venue to get started.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($venues->hasPages())
            <div class="mt-4">{{ $venues->withQueryString()->links() }}</div>
        @endif
    </div>
</x-layouts.app>
