<x-layouts.app :title="'User Management'">
    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <h1 class="font-heading text-3xl font-bold text-stone-900">User Management</h1>
            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Owner Only</span>
        </div>

        <div class="flex flex-col sm:flex-row gap-3 sm:items-end justify-between">
            <form method="GET" action="{{ route('user-management.index') }}" class="flex gap-3 max-w-md">
                @if($showTrashed)
                    <input type="hidden" name="trashed" value="1">
                @endif
                <input type="text" name="search" placeholder="Search by name or email..." value="{{ $search ?? '' }}" class="flex-1 rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <button type="submit" class="inline-flex items-center rounded-lg bg-emerald-700 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Search</button>
                @if($search)
                    <a href="{{ route('user-management.index', $showTrashed ? ['trashed' => 1] : []) }}" class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">Clear</a>
                @endif
            </form>

            <div class="flex items-center gap-2">
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

        <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b-2 border-stone-200 bg-stone-50">
                        <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Name</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Email</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Roles</th>
                        @if(! $showTrashed)
                            <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Membership</th>
                        @else
                            <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Deleted</th>
                        @endif
                        <th class="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($users as $user)
                        <tr class="hover:bg-stone-50 transition-colors">
                            <td class="whitespace-nowrap px-5 py-3.5 text-sm font-medium text-stone-900">{{ $user->name }}</td>
                            <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $user->email }}</td>
                            <td class="px-5 py-3.5 text-sm">
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
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                                    @if($user->membership)
                                        <div class="flex flex-col gap-1">
                                        <div class="flex items-center gap-1.5">
                                        @if($user->membership->membership_type === 'free')
                                            <span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-500 ring-1 ring-inset ring-stone-500/20" title="Registered to shoot a provincial — not a paid-up member">Non-member (free)</span>
                                        @else
                                        @switch($user->membership->status)
                                            @case('active')
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                                                @break
                                            @case('lapsed')
                                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Lapsed</span>
                                                @break
                                            @case('expired')
                                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Expired</span>
                                                @break
                                            @case('revoked')
                                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Revoked</span>
                                                @break
                                            @case('pending')
                                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Pending</span>
                                                @break
                                            @default
                                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">{{ ucfirst($user->membership->status) }}</span>
                                        @endswitch
                                        @endif
                                        </div>
                                        <span class="text-[11px] text-stone-400">
                                            @if($user->membership->expiry_date)
                                                Expires {{ $user->membership->expiry_date->format('d M Y') }}
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
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">
                                    {{ $user->deleted_at->format('d M Y H:i') }}
                                </td>
                            @endif
                            <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm">
                                @if($showTrashed)
                                    <div class="flex items-center justify-end gap-2">
                                        <form method="POST" action="{{ route('user-management.restore', $user->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50 hover:text-emerald-900 transition">Restore</button>
                                        </form>
                                        <a href="{{ route('user-management.confirm-force-delete', $user->id) }}"
                                           class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 hover:text-red-800 transition">
                                            Permanent Delete
                                        </a>
                                    </div>
                                @else
                                    <div class="flex items-center justify-end gap-2">
                                        @if(!$user->hasRole('owner') || auth()->user()->hasRole('owner'))
                                            <a href="{{ route('user-management.edit', $user) }}" class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50 hover:text-emerald-900 transition">Edit</a>
                                        @endif
                                        @if(!$user->hasRole('owner') && $user->id !== auth()->id())
                                            <form method="POST" action="{{ route('user-management.destroy', $user) }}" class="inline"
                                                  onsubmit="return confirm('Delete {{ addslashes($user->name) }}? They will be moved to the deleted users list and can be restored later.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 hover:text-red-800 transition">Delete</button>
                                            </form>
                                        @elseif($user->hasRole('owner'))
                                            <span class="text-xs text-stone-400">Protected</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center text-sm text-stone-400">
                                {{ $showTrashed ? 'No deleted users.' : 'No users found.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $users->links() }}
        </div>
    </div>
</x-layouts.app>
