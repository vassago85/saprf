<x-layouts.app :title="'Provincial Members - SAPRF'">
    <div class="space-y-6">
        <div>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Provincial Members</h1>
            <p class="mt-1 text-sm text-stone-500">Members registered in your province.</p>
        </div>

        <form method="GET" action="{{ route('provincial-members.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-stone-500 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, email, SAPRF number..."
                    class="rounded-lg border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-stone-500 mb-1">Province</label>
                <select name="province_id" class="rounded-lg border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All Provinces</option>
                    @foreach ($provinces as $prov)
                        <option value="{{ $prov->id }}" @selected(request('province_id') == $prov->id)>{{ $prov->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit"
                class="rounded-lg bg-stone-100 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">
                Filter
            </button>
        </form>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b-2 border-stone-200 bg-stone-50">
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Name</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">SAPRF Number</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Email</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Phone</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Province</th>
                            @if ($showSaId)
                                <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">SA ID</th>
                            @endif
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Expiry</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($users as $user)
                            <tr class="hover:bg-stone-50 transition-colors">
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm font-medium text-stone-900">{{ $user->name }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm font-mono text-stone-500">{{ $user->membership?->saprf_number ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $user->email }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $user->phone ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $user->province?->name ?? '—' }}</td>
                                @if ($showSaId)
                                    <td class="whitespace-nowrap px-5 py-3.5 text-sm font-mono text-stone-500">{{ $user->sa_id_number ?? '—' }}</td>
                                @endif
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                                    @switch($user->membership?->status)
                                        @case('active')
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                                            @break
                                        @case('pending')
                                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                            @break
                                        @case('lapsed')
                                            <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Lapsed</span>
                                            @break
                                        @case('suspended')
                                            <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Suspended</span>
                                            @break
                                    @endswitch
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $user->membership?->expiry_date?->format('d M Y') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $showSaId ? 8 : 7 }}" class="px-5 py-12 text-center text-sm text-stone-400">No members found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($users->hasPages())
            <div class="mt-4">{{ $users->withQueryString()->links() }}</div>
        @endif
    </div>
</x-layouts.app>
