<x-layouts.app :title="'Edit User Roles'">
    <div class="max-w-xl space-y-8">
        <div class="flex items-center justify-between">
            <h1 class="font-heading text-3xl font-bold text-stone-900">Edit User Roles</h1>
            <a href="{{ route('user-management.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">← Back</a>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Name</dt>
                    <dd class="mt-1 font-medium text-stone-900">{{ $user->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Email</dt>
                    <dd class="mt-1 font-medium text-stone-900">{{ $user->email }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Current Roles</dt>
                    <dd class="mt-1 flex flex-wrap gap-1.5">
                        @forelse ($user->getRoleNames() as $role)
                            @php
                                $badgeClass = match($role) {
                                    'owner' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                    'admin' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                    'match_director' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                                    default => 'bg-stone-100 text-stone-600 ring-stone-500/20',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $badgeClass }}">
                                {{ str_replace('_', ' ', ucfirst($role)) }}
                            </span>
                        @empty
                            <span class="text-sm text-stone-400">None</span>
                        @endforelse
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Joined</dt>
                    <dd class="mt-1 font-medium text-stone-900">{{ $user->created_at->format('d M Y') }}</dd>
                </div>
            </dl>
        </div>

        <form method="POST" action="{{ route('user-management.update-role', $user) }}" class="space-y-6">
            @csrf
            @method('PUT')

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-3">Assign Roles</label>
                    <p class="text-xs text-stone-400 mb-4">Users can hold multiple roles. A match director or admin who also competes should have the Member role as well.</p>

                    <div class="space-y-3">
                        @foreach ($assignableRoles as $role)
                            @php
                                $descriptions = [
                                    'member' => 'Can register for matches, view own scores and standings',
                                    'match_director' => 'Can create/manage matches, upload scores',
                                    'admin' => 'Can manage memberships, override scores, view audit logs',
                                ];
                            @endphp
                            <label class="flex items-start gap-3 rounded-lg border border-stone-200 p-3 hover:bg-stone-50 transition cursor-pointer">
                                <input type="checkbox" name="roles[]" value="{{ $role }}"
                                    class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500"
                                    @checked(in_array($role, old('roles', $user->getRoleNames()->toArray())))>
                                <div>
                                    <span class="text-sm font-semibold text-stone-900">{{ str_replace('_', ' ', ucfirst($role)) }}</span>
                                    <p class="text-xs text-stone-500 mt-0.5">{{ $descriptions[$role] ?? '' }}</p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                @if ($user->hasRole('owner'))
                    <div class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-700">
                        This user has the Owner role which cannot be removed here. The roles below are in addition to Owner.
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Update Roles
                </button>
                <a href="{{ route('user-management.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
