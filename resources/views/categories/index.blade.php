<x-layouts.app :title="'Categories'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Categories</h1>
                <p class="mt-1 text-sm text-stone-500">Manage shooter categories.</p>
            </div>
            <a href="{{ route('categories.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Create Category
            </a>
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <table class="min-w-full divide-y divide-stone-200">
                <thead class="bg-stone-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Slug</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Description</th>
                        <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-stone-500">Display Order</th>
                        <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-stone-500">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($categories as $category)
                        <tr class="{{ !$category->is_active ? 'opacity-50 bg-stone-50' : '' }}">
                            <td class="px-6 py-4 text-sm font-mono font-medium text-stone-900">{{ $category->slug }}</td>
                            <td class="px-6 py-4 text-sm text-stone-700">{{ $category->name }}</td>
                            <td class="px-6 py-4 text-sm text-stone-600 max-w-xs">{{ Str::limit($category->description ?? '—', 80) }}</td>
                            <td class="px-6 py-4 text-center text-sm text-stone-600">{{ $category->display_order }}</td>
                            <td class="px-6 py-4 text-center">
                                @if ($category->is_active)
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Active</span>
                                @else
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-stone-100 text-stone-600">Archived</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('categories.edit', $category) }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-900">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-stone-400">
                                No categories defined yet. Create one to get started.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
