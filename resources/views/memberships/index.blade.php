<x-layouts.app :title="'Memberships'">
    @php
        $sortLink = function (string $col) use ($filters, $sort, $dir) {
            $nextDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
            $params = array_merge($filters, ['sort' => $col, 'dir' => $nextDir]);
            $arrow = $sort === $col ? ($dir === 'asc' ? '▲' : '▼') : '';
            return [route('memberships.index', $params), $arrow];
        };
    @endphp

    <x-page-header title="Memberships">
        <x-slot:actions>
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
        </x-slot:actions>
    </x-page-header>

    @if($isAdmin)
        {{-- Legend: Type vs Status --}}
        <details class="mt-6 rounded-lg border border-stone-200 bg-stone-50 text-sm">
            <summary class="cursor-pointer select-none px-4 py-2.5 font-medium text-stone-700 hover:text-stone-900">
                What do <span class="font-semibold">Type</span> and <span class="font-semibold">Status</span> mean?
            </summary>
            <div class="px-4 pb-4 pt-1 grid gap-4 sm:grid-cols-2 text-xs text-stone-600">
                <div>
                    <p class="font-semibold text-stone-800 mb-1">Type — the membership plan</p>
                    <ul class="space-y-1">
                        <li><span class="inline-block w-16 font-mono text-stone-500">Full</span> Standard paying member (voting rights).</li>
                        <li><span class="inline-block w-16 font-mono text-stone-500">Associate</span> Non-voting paying member.</li>
                        <li><span class="inline-block w-16 font-mono text-stone-500">Junior</span> Under-18 member (reduced rate).</li>
                        <li><span class="inline-block w-16 font-mono text-stone-500">Free</span> Non-paying account (e.g. guests, admins). Never counts as a valid membership for scoring.</li>
                        <li><span class="inline-block w-16 font-mono text-stone-500">Paid</span> Legacy import label — treated the same as Full for scoring.</li>
                    </ul>
                </div>
                <div>
                    <p class="font-semibold text-stone-800 mb-1">Status — the current state of that plan</p>
                    <ul class="space-y-1">
                        <li><span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span> Paid up and within the membership window.</li>
                        <li><span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span> Registered but not yet paid / approved.</li>
                        <li><span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-600/20">Lapsed (in grace)</span> Paid membership inside the 90-day window after expiry.</li>
                        <li><span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Expired</span> Paid membership past that grace window.</li>
                        <li><span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-800 ring-1 ring-inset ring-red-700/30">Revoked / Suspended</span> Manually terminated by an admin.</li>
                        <li><span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-600 ring-1 ring-inset ring-stone-400/20">Non-member</span> Free account. A stored expiry on a free record is not a membership.</li>
                    </ul>
                    <p class="mt-2 text-[11px] text-stone-500">A row can read e.g. <em>Free / Non-member</em> (a guest account) or <em>Full / Expired</em> (a paying member whose renewal is overdue).</p>
                </div>
            </div>
        </details>

        <h2 class="sr-only">Filters</h2>
        <x-filter-bar :action="route('memberships.index')" label="Membership filters">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $dir }}">
            <div class="flex-1 min-w-[220px]">
                <label for="memberships_search" class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Search</label>
                <input type="text" id="memberships_search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name, email or SAPRF number" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            </div>
            <div>
                <label for="memberships_province" class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Province</label>
                <select id="memberships_province" name="province_id" class="rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <option value="">All</option>
                    @foreach($provinces as $p)
                        <option value="{{ $p->id }}" @selected(($filters['province_id'] ?? '') == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="memberships_status" class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Status</label>
                <select id="memberships_status" name="status" class="rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <option value="">All</option>
                    @foreach(\App\Enums\MembershipStatus::cases() as $case)
                        <option value="{{ $case->value }}" @selected(($filters['status'] ?? '') === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2">
                @if(array_filter($filters))
                    <a href="{{ route('memberships.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Clear</a>
                @endif
            </div>
        </x-filter-bar>

        <h2 class="sr-only">Memberships list</h2>
    @endif

    <x-admin-table>
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
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm font-medium text-stone-900">
                            {{ $membership->user->name }}
                            @if($membership->user?->email_bounced_at)
                                <div class="mt-1 text-xs font-semibold text-rose-700" title="Hard bounce recorded {{ date_short($membership->user->email_bounced_at) }}. Repeat mail to this address is skipped until the email is changed.">Email bouncing</div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm font-mono text-stone-500">{{ $membership->saprf_number ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500 capitalize">{{ $membership->membership_type }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                            <x-membership-status :membership="$membership" />
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $membership->user->province?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">
                            @if($membership->membershipStatus()->showsExpiry())
                                {{ date_short($membership->expiry_date) }}
                            @else
                                <span title="Free accounts are not memberships, so they have no expiry.">—</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm">
                            <x-row-actions>
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
                                <x-row-action :href="route('memberships.show', $membership)" title="View">
                                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                </x-row-action>
                                @role('owner|admin')
                                    <x-row-action :href="route('memberships.edit', $membership)" title="Edit">
                                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                    </x-row-action>
                                @endrole
                                @role('developer')
                                    @if($membership->user_id !== auth()->id())
                                        <form method="POST" action="{{ route('impersonate.start', $membership->user) }}" class="inline">
                                            @csrf
                                            <x-row-action
                                                variant="danger"
                                                title="View as this member (developer support tool — audit logged)"
                                                :confirm="'View SAPRF as '.$membership->user->name.'? This action is logged. You will need to click Return to yourself to exit.'"
                                            >
                                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                            </x-row-action>
                                        </form>
                                    @endif
                                @endrole
                            </x-row-actions>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-sm text-stone-400">No memberships found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-admin-table>

    <div class="mt-6">
        {{ $memberships->withQueryString()->links() }}
    </div>
</x-layouts.app>
