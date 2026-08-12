<x-layouts.app :title="'Edit ' . $club->name">
    <div class="max-w-3xl space-y-6">
        <div>
            <a href="{{ route('clubs.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Back to clubs</a>
            <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Edit Club</h1>
            <p class="mt-1 text-sm text-stone-500">{{ $club->users_count }} {{ Str::plural('member', $club->users_count) }} affiliated to this club.</p>
        </div>

        <form method="POST" action="{{ route('clubs.update', $club) }}" class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-6">
            @csrf
            @method('PUT')
            @include('clubs._form')

            <div class="flex items-center gap-3 border-t border-stone-200 pt-4">
                <button type="submit" class="rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Save Changes</button>
                <a href="{{ route('clubs.index') }}" class="text-sm text-stone-500 hover:text-stone-800">Cancel</a>
            </div>
        </form>

        @can('delete', $club)
            <div class="rounded-xl border border-red-200 bg-red-50 p-6">
                <h2 class="text-sm font-semibold text-red-900 mb-2">Danger Zone</h2>
                @if ($club->users_count > 0)
                    <p class="text-sm text-red-800 mb-3">This club still has {{ $club->users_count }} {{ Str::plural('member', $club->users_count) }}. You can't delete it — either reassign the members first, or use <a href="{{ route('clubs.merge-form', $club) }}" class="underline">Merge</a> to move them into another club.</p>
                    <button type="button" disabled class="rounded-lg bg-red-300 px-4 py-2 text-sm font-medium text-white cursor-not-allowed">Delete Club</button>
                @else
                    <p class="text-sm text-red-800 mb-3">No members are affiliated — safe to delete permanently.</p>
                    <form method="POST" action="{{ route('clubs.destroy', $club) }}" onsubmit="return confirm('Delete {{ $club->name }} permanently?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Delete Club</button>
                    </form>
                @endif
            </div>
        @endcan
    </div>
</x-layouts.app>
