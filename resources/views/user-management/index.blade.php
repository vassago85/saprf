<x-layouts.app :title="'User Management'">
    <div class="space-y-6">
        <x-page-header title="User Management">
            <x-slot:actions>
                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Owner Only</span>
            </x-slot:actions>
        </x-page-header>

        <div class="flex flex-col sm:flex-row gap-3 sm:items-end justify-between">
            <h2 class="sr-only">Filters</h2>
            <x-filter-bar :action="route('user-management.index')" label="User filters" class="mt-0 flex-1">
                @if($showTrashed)
                    <input type="hidden" name="trashed" value="1">
                @endif
                <div class="flex-1 min-w-[220px]">
                    <label for="user_management_search" class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Search</label>
                    <input type="text" id="user_management_search" name="search" placeholder="Name, email or SAPRF number…" value="{{ $search ?? '' }}" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                </div>

                {{-- Bot-sweep filters. Only shown on the active-users view;
                     the trashed view has no email/login signal to filter on. --}}
                @if(! $showTrashed)
                    <div class="min-w-[160px]">
                        <label for="user_management_email_status" class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Email</label>
                        <select id="user_management_email_status" name="email_status" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <option value="all"       @selected(($filters['email_status'] ?? 'all') === 'all')>Any</option>
                            <option value="ok"        @selected(($filters['email_status'] ?? 'all') === 'ok')>Deliverable</option>
                            <option value="bouncing"  @selected(($filters['email_status'] ?? 'all') === 'bouncing')>Hard-bouncing</option>
                            <option value="complained" @selected(($filters['email_status'] ?? 'all') === 'complained')>Marked as spam</option>
                        </select>
                    </div>
                    <div class="min-w-[160px]">
                        <label for="user_management_verified" class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Verified</label>
                        <select id="user_management_verified" name="verified" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <option value="all" @selected(($filters['verified'] ?? 'all') === 'all')>Any</option>
                            <option value="yes" @selected(($filters['verified'] ?? 'all') === 'yes')>Verified</option>
                            <option value="no"  @selected(($filters['verified'] ?? 'all') === 'no')>Unverified</option>
                        </select>
                    </div>
                    <div class="min-w-[180px]">
                        <label for="user_management_login_history" class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Login history</label>
                        <select id="user_management_login_history" name="login_history" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <option value="all"       @selected(($filters['login_history'] ?? 'all') === 'all')>Any</option>
                            <option value="logged_in" @selected(($filters['login_history'] ?? 'all') === 'logged_in')>Has signed in</option>
                            <option value="never"     @selected(($filters['login_history'] ?? 'all') === 'never')>Never signed in</option>
                        </select>
                    </div>
                @endif

                @php
                    $hasActiveFilter = $search
                        || (($filters['email_status'] ?? 'all') !== 'all')
                        || (($filters['verified'] ?? 'all') !== 'all')
                        || (($filters['login_history'] ?? 'all') !== 'all');
                @endphp
                @if($hasActiveFilter)
                    <a href="{{ route('user-management.index', $showTrashed ? ['trashed' => 1] : []) }}" class="rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100">Clear</a>
                @endif
            </x-filter-bar>

            <div class="flex items-center gap-2 shrink-0">
                @if($showTrashed)
                    <a href="{{ route('user-management.index') }}" class="inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900 transition">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-1.396M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-1.396"/></svg>
                        Active Users
                    </a>
                @else
                    @if($trashedCount > 0)
                        <a href="{{ route('user-management.index', ['trashed' => 1]) }}" class="inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-medium text-red-600 hover:bg-red-50 hover:text-red-800 transition">
                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                            Deleted ({{ $trashedCount }})
                        </a>
                    @endif
                @endif
            </div>
        </div>

        @if($showTrashed)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Showing deleted users. You can restore them or permanently delete them from here.
            </div>
        @endif

        <h2 class="sr-only">Users list</h2>

        {{-- Bulk-delete form wraps the table on the active-users view only.
             Trashed users already have per-row Restore / Permanent-delete
             actions; a bulk soft-delete there wouldn't make sense. --}}
        @if(! $showTrashed)
            <form id="user-management-bulk" method="POST" action="{{ route('user-management.bulk-destroy') }}"
                  onsubmit="return confirmBulkDelete(this)">
                @csrf
                <input type="hidden" name="search" value="{{ $search ?? '' }}">
                <input type="hidden" name="email_status" value="{{ $filters['email_status'] ?? 'all' }}">
                <input type="hidden" name="verified" value="{{ $filters['verified'] ?? 'all' }}">
                <input type="hidden" name="login_history" value="{{ $filters['login_history'] ?? 'all' }}">
        @endif

        <x-admin-table class="mt-0">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b-2 border-stone-200 bg-stone-50">
                        @if(! $showTrashed)
                            <th class="w-8 px-3 py-3.5 text-left">
                                <label class="sr-only" for="user-management-select-all">Select all users on this page</label>
                                <input type="checkbox" id="user-management-select-all"
                                       onclick="document.querySelectorAll('.user-management-row-check').forEach(c => c.checked = this.checked)"
                                       class="h-4 w-4 rounded border-stone-300 text-emerald-700 focus:ring-emerald-500">
                            </th>
                        @endif
                        {{--
                            Responsive columns: Name + Actions always visible.
                            Email collapses below `md`; it shows under the Name
                            as a muted sub-line on mobile so you don't lose the
                            info entirely. Roles collapses below `md`,
                            Membership/Deleted below `lg`.
                        --}}
                        <th class="px-3 md:px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Name</th>
                        <th class="hidden md:table-cell px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Email</th>
                        <th class="hidden md:table-cell px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Roles</th>
                        @if(! $showTrashed)
                            <th class="hidden lg:table-cell px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Membership</th>
                        @else
                            <th class="hidden lg:table-cell px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Deleted</th>
                        @endif
                        <th class="px-3 md:px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($users as $user)
                        <tr class="hover:bg-stone-50 transition-colors">
                            @if(! $showTrashed)
                                <td class="w-8 px-3 py-3.5">
                                    @if(! $user->hasRole('owner') && $user->id !== auth()->id())
                                        <label class="sr-only" for="user-check-{{ $user->id }}">Select {{ $user->name }} for bulk delete</label>
                                        <input type="checkbox" form="user-management-bulk" name="user_ids[]" value="{{ $user->id }}"
                                               id="user-check-{{ $user->id }}"
                                               class="user-management-row-check h-4 w-4 rounded border-stone-300 text-emerald-700 focus:ring-emerald-500">
                                    @else
                                        <span class="text-xs text-stone-300" title="Owners and your own account can't be bulk-deleted">—</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-3 md:px-5 py-3.5 text-sm font-medium text-stone-900">
                                <div class="truncate max-w-[12rem] md:max-w-none">{{ $user->name }}</div>
                                {{-- Mobile-only: show email under name since the Email column is hidden below md. --}}
                                <div class="md:hidden text-xs text-stone-500 truncate max-w-[12rem]">{{ $user->email }}</div>
                                @if(! $showTrashed && $user->email_bounced_at)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700 ring-1 ring-inset ring-rose-600/20" title="Hard bounce recorded {{ date_short($user->email_bounced_at) }}">Bouncing</span>
                                @endif
                                @if(! $showTrashed && $user->email_complained_at)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20" title="Recipient marked a previous email as spam {{ date_short($user->email_complained_at) }}">Spam complaint</span>
                                @endif
                                @if(! $showTrashed && ! $user->email_verified_at)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-stone-100 px-1.5 py-0.5 text-[10px] font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20" title="This account's email address has never been verified">Unverified</span>
                                @endif
                            </td>
                            <td class="hidden md:table-cell whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $user->email }}</td>
                            <td class="hidden md:table-cell px-5 py-3.5 text-sm">
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($user->getRoleNames() as $role)
                                        @php
                                            $badgeClass = match($role) {
                                                'owner' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                                'admin' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                                'match_director' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                                                default => 'bg-stone-100 text-stone-600 ring-stone-500/20',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $badgeClass }}">
                                            {{ str_replace('_', ' ', ucfirst($role)) }}
                                        </span>
                                    @empty
                                        <span class="text-xs text-stone-400">None</span>
                                    @endforelse
                                </div>
                            </td>
                            @if(! $showTrashed)
                                <td class="hidden lg:table-cell whitespace-nowrap px-5 py-3.5 text-sm">
                                    @if($user->membership)
                                        <div class="flex flex-col gap-1">
                                        <div class="flex items-center gap-1.5">
                                            <x-membership-status :membership="$user->membership" />
                                        </div>
                                        <span class="text-[11px] text-stone-400">
                                            @if($user->membership->membershipStatus()->showsExpiry() && $user->membership->expiry_date)
                                                Expires {{ date_short($user->membership->expiry_date) }}
                                            @elseif(! $user->membership->membershipStatus()->showsExpiry())
                                                Free account, no membership expiry
                                            @else
                                                No expiry date
                                            @endif
                                        </span>
                                        </div>
                                    @else
                                        <span class="text-xs text-stone-400">None</span>
                                    @endif
                                </td>
                            @else
                                <td class="hidden lg:table-cell whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">
                                    {{ datetime_short($user->deleted_at) }}
                                </td>
                            @endif
                            <td class="whitespace-nowrap px-3 md:px-5 py-3.5 text-right text-sm">
                                <x-row-actions>
                                    @if($showTrashed)
                                        <form method="POST" action="{{ route('user-management.restore', $user->id) }}" class="inline">
                                            @csrf
                                            <x-row-action type="submit" variant="primary" title="Restore">
                                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" /></svg>
                                            </x-row-action>
                                        </form>
                                        <x-row-action :href="route('user-management.confirm-force-delete', $user->id)" variant="danger" title="Permanent delete">
                                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        </x-row-action>
                                    @else
                                        @if(! $user->hasRole('owner') || auth()->user()->hasAnyRole(['owner', 'developer']))
                                            <x-row-action :href="route('user-management.edit', $user)" variant="primary" title="Edit">
                                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                            </x-row-action>
                                        @endif
                                        @if(! $user->hasRole('owner') && $user->id !== auth()->id())
                                            <form method="POST" action="{{ route('user-management.destroy', $user) }}" class="inline"
                                                  onsubmit="return confirm('⚠  Delete member: {{ addslashes($user->name) }}\n(SAPRF #{{ addslashes($user->membership?->saprf_number ?? '—') }} · {{ addslashes($user->email) }})\n\nThis is a soft delete — the member will be moved to the deleted users list and can be restored from there. Their scores, registrations and history stay intact.\n\nProceed?')">
                                                @csrf
                                                @method('DELETE')
                                                <x-row-action type="submit" variant="danger" title="Delete">
                                                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                                </x-row-action>
                                            </form>
                                        @elseif($user->hasRole('owner') && ! auth()->user()->hasAnyRole(['owner', 'developer']))
                                            <span class="text-xs text-stone-400 px-1.5">Protected</span>
                                        @endif
                                    @endif
                                </x-row-actions>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $showTrashed ? 5 : 6 }}" class="px-5 py-12 text-center text-sm text-stone-400">
                                {{ $showTrashed ? 'No deleted users.' : 'No users found.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-admin-table>

        @if(! $showTrashed)
                <div class="flex items-center justify-between gap-3 pt-3">
                    <p class="text-xs text-stone-500">
                        Owners and your own account are always skipped. Soft-deleted users land in the Deleted list and can be restored.
                    </p>
                    <button type="submit"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 transition disabled:opacity-50">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                        Delete selected
                    </button>
                </div>
            </form>

            <script>
                function confirmBulkDelete(form) {
                    const picked = form.querySelectorAll('input[name="user_ids[]"]:checked').length;
                    if (picked === 0) {
                        alert('Pick at least one user to delete.');
                        return false;
                    }
                    return confirm('⚠  Soft-delete ' + picked + ' user' + (picked === 1 ? '' : 's') + '?\n\n' +
                        'They will be moved to the Deleted list and can be restored from there. ' +
                        'Owner accounts and your own account are always skipped automatically.');
                }
            </script>
        @endif

        <div>
            {{ $users->links() }}
        </div>
    </div>
</x-layouts.app>
