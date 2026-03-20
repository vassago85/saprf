<x-layouts.app :title="'User Management'">
    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <h1 class="font-heading text-3xl font-bold text-stone-900">User Management</h1>
            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Owner Only</span>
        </div>

        <form method="GET" action="{{ route('user-management.index') }}" class="flex gap-3 max-w-md">
            <input type="text" name="search" placeholder="Search by name or email..." value="{{ $search ?? '' }}" class="flex-1 rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm placeholder:text-stone-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            <button type="submit" class="inline-flex items-center rounded-lg bg-emerald-700 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Search</button>
            @if($search)
                <a href="{{ route('user-management.index') }}" class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">Clear</a>
            @endif
        </form>

        <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b-2 border-stone-200 bg-stone-50">
                        <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Name</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Email</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Roles</th>
                        <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Active</th>
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
                            <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                                @if($user->is_active)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Yes</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">No</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm">
                                @if(!$user->hasRole('owner') || auth()->user()->hasRole('owner'))
                                    <a href="{{ route('user-management.edit', $user) }}" class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50 hover:text-emerald-900">Edit Roles</a>
                                @else
                                    <span class="text-xs text-stone-400">Protected</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center text-sm text-stone-400">No users found.</td>
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
