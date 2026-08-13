<x-layouts.app :title="'Divisions'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Divisions</h1>
                <p class="mt-1 text-sm text-stone-500">Manage competition divisions.</p>
            </div>
            <a href="{{ route('divisions.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Create Division
            </a>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200">
                <thead class="bg-stone-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Slug</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Name</th>
                        <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-stone-500">Display Order</th>
                        <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-stone-500">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($divisions as $division)
                        <tr class="{{ !$division->is_active ? 'opacity-50 bg-stone-50' : '' }}">
                            <td class="px-6 py-4 text-sm font-mono font-medium text-stone-900">{{ $division->slug }}</td>
                            <td class="px-6 py-4 text-sm text-stone-700">{{ $division->name }}</td>
                            <td class="px-6 py-4 text-center text-sm text-stone-600">{{ $division->display_order }}</td>
                            <td class="px-6 py-4 text-center">
                                @if ($division->is_active)
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Active</span>
                                @else
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-stone-100 text-stone-600">Archived</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('divisions.edit', $division) }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-900">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-sm text-stone-400">
                                No divisions defined yet. Create one to get started.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
