<x-layouts.app :title="'Edit Division'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Edit Division: {{ $division->name }}</h1>
                <p class="mt-1 text-sm text-stone-500">Update division settings.</p>
            </div>
            <a href="{{ route('divisions.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">← Back to Divisions</a>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('divisions.update', $division) }}" class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf
            @method('PUT')

            <div class="grid sm:grid-cols-2 gap-6">
                <div>
                    <label for="slug" class="block text-sm font-medium text-stone-700 mb-1">Slug</label>
                    <input type="text" name="slug" id="slug" value="{{ old('slug', $division->slug) }}" required placeholder="e.g. open, factory, limited"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Unique identifier. Letters, numbers, dashes, underscores only.</p>
                </div>

                <div>
                    <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Name</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $division->name) }}" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="display_order" class="block text-sm font-medium text-stone-700 mb-1">Display Order</label>
                    <input type="number" name="display_order" id="display_order" value="{{ old('display_order', $division->display_order) }}" min="0" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div class="sm:col-span-2">
                    <label for="description" class="block text-sm font-medium text-stone-700 mb-1">Description</label>
                    <textarea name="description" id="description" rows="3"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">{{ old('description', $division->description) }}</textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1"
                            class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500"
                            @checked(old('is_active', $division->is_active))>
                        <span class="text-sm text-stone-700">Active</span>
                    </label>
                    <p class="mt-1 text-xs text-stone-400">Uncheck to archive this division. Archived divisions won't appear in match setup.</p>
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <flux:button type="submit" variant="primary" class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Update Division
                </flux:button>
                <a href="{{ route('divisions.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
