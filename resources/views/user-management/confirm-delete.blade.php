<x-layouts.app :title="'Permanently Delete User'">
    <div class="max-w-xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="font-heading text-3xl font-bold text-red-900">Permanent Deletion</h1>
            <a href="{{ route('user-management.index', ['trashed' => 1]) }}" class="text-sm text-stone-600 font-medium hover:text-stone-800">&larr; Back</a>
        </div>

        <div class="rounded-xl border-2 border-red-300 bg-red-50 p-6 space-y-4">
            <div class="flex items-start gap-3">
                <div class="inline-flex items-center justify-center size-10 rounded-lg bg-red-100 text-red-700 shrink-0 mt-0.5">
                    <svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                </div>
                <div>
                    <h2 class="font-heading text-lg font-bold text-red-900">This action is irreversible</h2>
                    <p class="text-sm text-red-800 mt-1">
                        You are about to permanently delete <strong>{{ $user->name }}</strong> ({{ $user->email }}) and <strong>all</strong> of their associated data.
                        This cannot be undone.
                    </p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-lg font-semibold text-stone-900 mb-4">Data that will be destroyed</h2>

            <div class="space-y-3">
                <div class="flex items-center justify-between py-2 border-b border-stone-100">
                    <span class="text-sm text-stone-700">Match Scores</span>
                    <span class="text-sm font-mono font-semibold {{ $impact['scores'] > 0 ? 'text-red-700' : 'text-stone-400' }}">{{ $impact['scores'] }}</span>
                </div>
                <div class="flex items-center justify-between py-2 border-b border-stone-100">
                    <span class="text-sm text-stone-700">Match Registrations</span>
                    <span class="text-sm font-mono font-semibold {{ $impact['registrations'] > 0 ? 'text-red-700' : 'text-stone-400' }}">{{ $impact['registrations'] }}</span>
                </div>
                <div class="flex items-center justify-between py-2 border-b border-stone-100">
                    <span class="text-sm text-stone-700">Rifle Configurations</span>
                    <span class="text-sm font-mono font-semibold {{ $impact['rifles'] > 0 ? 'text-red-700' : 'text-stone-400' }}">{{ $impact['rifles'] }}</span>
                </div>
                <div class="flex items-center justify-between py-2 border-b border-stone-100">
                    <span class="text-sm text-stone-700">Ammo Loads</span>
                    <span class="text-sm font-mono font-semibold {{ $impact['ammo_loads'] > 0 ? 'text-red-700' : 'text-stone-400' }}">{{ $impact['ammo_loads'] }}</span>
                </div>
                <div class="flex items-center justify-between py-2 border-b border-stone-100">
                    <span class="text-sm text-stone-700">Membership Record</span>
                    <span class="text-sm font-semibold {{ $impact['membership'] ? 'text-red-700' : 'text-stone-400' }}">{{ $impact['membership'] ? 'Yes' : 'None' }}</span>
                </div>
                <div class="flex items-center justify-between py-2">
                    <span class="text-sm text-stone-700">Created Matches</span>
                    <span class="text-sm font-mono font-semibold {{ $impact['created_matches'] > 0 ? 'text-amber-700' : 'text-stone-400' }}">
                        {{ $impact['created_matches'] }}
                        @if($impact['created_matches'] > 0)
                            <span class="text-xs text-amber-600 font-normal ml-1">(ownership cleared)</span>
                        @endif
                    </span>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-red-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-base font-semibold text-stone-900 mb-3">Confirm by typing the user's email</h2>
            <p class="text-sm text-stone-500 mb-4">
                Type <strong class="font-mono text-red-700">{{ $user->email }}</strong> below to confirm permanent deletion.
            </p>

            <form method="POST" action="{{ route('user-management.force-delete', $user->id) }}" class="space-y-4">
                @csrf
                @method('DELETE')

                <div>
                    <input type="email" name="confirm_email" required autocomplete="off" placeholder="{{ $user->email }}"
                           class="w-full rounded-lg border-red-300 text-sm py-2.5 focus:ring-red-500 focus:border-red-500 placeholder:text-stone-300">
                    @error('confirm_email')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="px-5 py-2.5 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 transition">
                        Permanently Delete User
                    </button>
                    <a href="{{ route('user-management.index', ['trashed' => 1]) }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
