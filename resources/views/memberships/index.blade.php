<x-layouts.app :title="'Memberships'">
    @php
        $sortLink = function (string $col) use ($filters, $sort, $dir) {
            $nextDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
            $params = array_merge($filters, ['sort' => $col, 'dir' => $nextDir]);
            $arrow = $sort === $col ? ($dir === 'asc' ? '▲' : '▼') : '';
            return [route('memberships.index', $params), $arrow];
        };
    @endphp

    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Memberships</h1>

        <div class="flex items-center gap-2">
            @if($isAdmin && $pendingInviteCount > 0)
                <form method="POST" action="{{ route('memberships.invite-pending') }}" onsubmit="return confirm('Send an activation invitation email to {{ $pendingInviteCount }} member(s) who have not yet activated their account?');">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 shadow-sm hover:bg-emerald-100">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                        Invite Pending ({{ $pendingInviteCount }})
                    </button>
                </form>
            @endif
            @if($isAdmin)
                <a href="{{ route('memberships.csv', array_merge($filters, ['sort' => $sort, 'dir' => $dir])) }}" class="inline-flex items-center gap-2 rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm hover:bg-stone-50">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    Download CSV
                </a>
            @endif
            @role('owner|admin')
                <a href="{{ route('memberships.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Create Membership
                </a>
            @endrole
        </div>
    </div>

    @if($isAdmin)
        <form method="GET" action="{{ route('memberships.index') }}" class="mt-6 flex flex-wrap items-end gap-3">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $dir }}">
            <div class="flex-1 min-w-[220px]">
                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Search</label>
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name, email or SAPRF number" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Province</label>
                <select name="province_id" class="rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <option value="">All</option>
                    @foreach($provinces as $p)
                        <option value="{{ $p->id }}" @selected(($filters['province_id'] ?? '') == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Status</label>
                <select name="status" class="rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <option value="">All</option>
                    @foreach(['active' => 'Active', 'expired' => 'Expired', 'non_member' => 'Non-member', 'revoked' => 'Revoked'] as $val => $label)
                        <option value="{{ $val }}" @selected(($filters['status'] ?? '') === $val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Filter</button>
                @if(array_filter($filters))
                    <a href="{{ route('memberships.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Clear</a>
                @endif
            </div>
        </form>
    @endif

    <div class="mt-6 overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full">
            <thead>
                <tr class="border-b-2 border-stone-200 bg-stone-50">
                    @php
                        // key => [label, sortable?]
                        $cols = [
                            'name' => ['Member', true],
                            'saprf_number' => ['SAPRF Number', true],
                            'type' => ['Type', true],
                            'status' => ['Status', false],
                            'province' => ['Province', true],
                            'expiry' => ['Expiry', true],
                        ];
                    @endphp
                    @foreach($cols as $key => [$label, $sortable])
                        <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">
                            @if($isAdmin && $sortable)
                                @php [$url, $arrow] = $sortLink($key); @endphp
                                <a href="{{ $url }}" class="inline-flex items-center gap-1 hover:text-stone-800">{{ $label }} <span class="text-emerald-600">{{ $arrow }}</span></a>
                            @else
                                {{ $label }}
                            @endif
                        </th>
                    @endforeach
                    <th class="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($memberships as $membership)
                    <tr class="hover:bg-stone-50 transition-colors">
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm font-medium text-stone-900">{{ $membership->user->name }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm font-mono text-stone-500">{{ $membership->saprf_number ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500 capitalize">{{ $membership->membership_type }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                            @switch($membership->effective_status)
                                @case('active')
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                                    @break
                                @case('expired')
                                    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Expired</span>
                                    @break
                                @case('pending')
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                    @break
                                @case('revoked')
                                    <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-800 ring-1 ring-inset ring-red-700/30">Revoked</span>
                                    @break
                                @default
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">Non-member</span>
                            @endswitch
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $membership->user->province?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                @if($isAdmin && $membership->user && ! $membership->user->is_managed_account && ! $membership->user->hasOnboarded() && filled($membership->user->email))
                                    @php $invited = $membership->user->invitation_sent_at !== null; @endphp
                                    <form method="POST" action="{{ route('memberships.invite', $membership) }}" onsubmit="return confirm('Send an activation invitation email to {{ $membership->user->email }}?');">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100" title="{{ $invited ? 'Re-send activation invitation' : 'Send activation invitation' }}">
                                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                                            {{ $invited ? 'Re-invite' : 'Invite' }}
                                        </button>
                                    </form>
                                @endif
                                <a href="{{ route('memberships.show', $membership) }}" class="rounded-lg p-1.5 text-stone-400 hover:bg-stone-100 hover:text-stone-700" title="View">
                                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                </a>
                                @role('owner|admin')
                                    <a href="{{ route('memberships.edit', $membership) }}" class="rounded-lg p-1.5 text-stone-400 hover:bg-stone-100 hover:text-stone-700" title="Edit">
                                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                    </a>
                                @endrole
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-sm text-stone-400">No memberships found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $memberships->withQueryString()->links() }}
    </div>
</x-layouts.app>
