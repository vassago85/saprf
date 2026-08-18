<x-layouts.app :title="'Saved Distribution Lists'">
    <div class="max-w-5xl space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Saved distribution lists</h1>
                <p class="mt-1 text-sm text-stone-500">Reusable audience rule sets you can pick from the announcement composer.</p>
            </div>
            <a href="{{ route('saved-lists.create') }}"
                class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">+ New list</a>
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 uppercase tracking-wide text-xs text-stone-500">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">Name</th>
                        <th class="px-4 py-3 text-left font-semibold">Description</th>
                        <th class="px-4 py-3 text-left font-semibold">Rules</th>
                        <th class="px-4 py-3 text-left font-semibold">Created by</th>
                        <th class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($lists as $list)
                        <tr>
                            <td class="px-4 py-3 font-medium text-stone-900">{{ $list->name }}</td>
                            <td class="px-4 py-3 text-stone-500 max-w-xs">{{ $list->description ?? '—' }}</td>
                            <td class="px-4 py-3 text-stone-500">{{ count($list->rules ?? []) }}</td>
                            <td class="px-4 py-3 text-stone-500">{{ $list->creator?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right space-x-2">
                                <a href="{{ route('saved-lists.edit', $list) }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">Edit</a>
                                <form method="POST" action="{{ route('saved-lists.destroy', $list) }}" class="inline"
                                    onsubmit="return confirm('Delete this saved list? Announcements that reference it will silently resolve to zero recipients.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-sm font-medium text-red-700 hover:text-red-800">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-sm text-stone-400">
                                No saved lists yet. Create one to reuse audience rules across announcements.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $lists->links() }}</div>
    </div>
</x-layouts.app>
