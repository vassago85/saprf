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
                                    class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500"
                                    @checked(in_array($role, old('roles', $user->getRoleNames()->toArray())))>
                                <div>
                                    <span class="text-sm font-semibold text-stone-900">{{ str_replace('_', ' ', ucfirst($role)) }}</span>
                                    <p class="text-xs text-stone-500 mt-0.5">{{ $descriptions[$role] ?? '' }}</p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                @if ($user->hasRole('owner') && ! auth()->user()->hasRole('developer'))
                    <div class="rounded-lg bg-amber-50 border border-amber-200 p-3 text-xs text-amber-700">
                        This user has the Owner role which cannot be removed here. The roles above are in addition to Owner.
                    </div>
                @endif
            </div>

            {{-- Elevated / sysadmin-only roles. Visible only to developers. --}}
            @if(! empty($elevatedRoles))
                <div class="rounded-xl border-2 border-red-300 bg-red-50/50 p-6 shadow-sm space-y-4">
                    <div class="flex items-start gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-5 shrink-0 mt-0.5 text-red-700">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                        </svg>
                        <div class="flex-1">
                            <label class="block text-sm font-semibold text-red-900">Elevated Roles (developer-only)</label>
                            <p class="text-xs text-red-700/80 mt-1 leading-relaxed">
                                These roles bypass every permission check in the app. Only assign them to users who need federation-wide or sysadmin access. Every change is written to the audit log.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        @foreach ($elevatedRoles as $role)
                            @php
                                $elevatedDescriptions = [
                                    'provincial_admin' => 'Manages members and matches within their assigned province',
                                    'exco' => 'Federation-wide read/write bypass — shared board-walkthrough account',
                                    'chair' => 'Federation Chair — can send Policy change announcements unilaterally. Always grants Exco alongside.',
                                    'owner' => 'Federation superuser — full access, cannot be soft-deleted',
                                    'developer' => 'Sysadmin — bypasses every policy, can grant/revoke any role',
                                ];
                                $isChecked = in_array($role, old('roles', $user->getRoleNames()->toArray()));
                                $isSelfDeveloperRow = $role === 'developer' && $user->id === auth()->id();
                            @endphp
                            <label class="flex items-start gap-3 rounded-lg border {{ $isChecked ? 'border-red-400 bg-red-50' : 'border-red-200 bg-white' }} p-3 hover:bg-red-50 transition cursor-pointer">
                                <input type="checkbox" name="roles[]" value="{{ $role }}"
                                    class="mt-0.5 rounded border-red-300 text-red-600 focus:ring-red-500"
                                    @checked($isChecked)
                                    @disabled($isSelfDeveloperRow)>
                                <div class="flex-1">
                                    <span class="text-sm font-semibold text-red-900">{{ str_replace('_', ' ', ucfirst($role)) }}</span>
                                    <p class="text-xs text-red-700/80 mt-0.5">{{ $elevatedDescriptions[$role] ?? '' }}</p>
                                    @if($isSelfDeveloperRow)
                                        <p class="text-[11px] italic text-red-600 mt-1">Locked — you cannot remove your own developer role.</p>
                                        {{-- Preserve the value on submit even though the checkbox is disabled. --}}
                                        <input type="hidden" name="roles[]" value="developer">
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Update Roles
                </button>
                <a href="{{ route('user-management.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>

        @php
            $m = $user->membership;
            $typeOptions = collect(['paid', 'free']);
            if ($m && $m->membership_type && ! $typeOptions->contains($m->membership_type)) {
                $typeOptions->push($m->membership_type);
            }
        @endphp
        <form method="POST" action="{{ route('user-management.update-membership', $user) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-5">
                <div class="flex items-center justify-between">
                    <h2 class="font-heading text-base font-semibold text-stone-900">Membership</h2>
                    @if($m && $m->membership_type === 'free')
                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">Free registrant — non-member</span>
                    @endif
                </div>
                <p class="text-xs text-stone-400 -mt-3">A <strong>Free</strong> registrant (someone who registered only to shoot one provincial) is treated as a non-member: their scores show in the match but never count in the season log.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Type</label>
                        <select name="membership_type" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @foreach($typeOptions as $opt)
                                <option value="{{ $opt }}" @selected(old('membership_type', $m?->membership_type) === $opt)>{{ ucfirst($opt) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">SAPRF Number</label>
                        <input type="text" name="saprf_number" value="{{ old('saprf_number', $m?->saprf_number) }}" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Status</label>
                        <select name="status" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @foreach(['active', 'pending', 'lapsed', 'expired', 'revoked'] as $opt)
                                <option value="{{ $opt }}" @selected(old('status', $m?->status ?? 'active') === $opt)>{{ ucfirst($opt) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Payment</label>
                        <select name="payment_status" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            @foreach(['paid', 'unpaid', 'waived'] as $opt)
                                <option value="{{ $opt }}" @selected(old('payment_status', $m?->payment_status ?? 'unpaid') === $opt)>{{ ucfirst($opt) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Start Date</label>
                        <input type="date" name="start_date" value="{{ old('start_date', $m?->start_date?->format('Y-m-d')) }}" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wide text-stone-400 mb-1">Expiry Date</label>
                        <input type="date" name="expiry_date" value="{{ old('expiry_date', $m?->expiry_date?->format('Y-m-d')) }}" class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                </div>

                <div>
                    <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                        Save Membership
                    </button>
                </div>
            </div>
        </form>

        @if(!$user->hasRole('owner') && $user->id !== auth()->id())
            <div class="rounded-xl border border-red-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-base font-semibold text-red-800 mb-2">Danger Zone</h2>
                <p class="text-sm text-stone-500 mb-4">Delete this user. They will be soft-deleted and can be restored from the deleted users list.</p>
                <form method="POST" action="{{ route('user-management.destroy', $user) }}"
                      onsubmit="return confirm('⚠  Delete member: {{ addslashes($user->name) }}\n(SAPRF #{{ addslashes($user->membership?->saprf_number ?? '—') }} · {{ addslashes($user->email) }})\n\nThis is a soft delete — the member will be moved to the deleted users list and can be restored from there. Their scores, registrations and history stay intact.\n\nProceed?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-red-700 bg-white border border-red-300 hover:bg-red-50 transition">
                        Delete User
                    </button>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
