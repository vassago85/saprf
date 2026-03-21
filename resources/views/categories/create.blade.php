<x-layouts.app :title="'Create Category'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Create Category</h1>
                <p class="mt-1 text-sm text-stone-500">Add a new competitor category.</p>
            </div>
            <a href="{{ route('categories.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">← Back to Categories</a>
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

        <form method="POST" action="{{ route('categories.store') }}" class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl"
              x-data="{ ageBased: {{ old('is_age_based') ? 'true' : 'false' }} }">
            @csrf

            <div class="grid sm:grid-cols-2 gap-6">
                <div>
                    <label for="code" class="block text-sm font-medium text-stone-700 mb-1">Code</label>
                    <input type="text" name="code" id="code" value="{{ old('code') }}" required placeholder="e.g. junior, veteran"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Unique identifier. Letters, numbers, dashes, underscores only.</p>
                </div>

                <div>
                    <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Name</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" required placeholder="e.g. Junior, Veteran"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div class="sm:col-span-2">
                    <label for="description" class="block text-sm font-medium text-stone-700 mb-1">Description</label>
                    <textarea name="description" id="description" rows="3" placeholder="Optional description of this category…"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">{{ old('description') }}</textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="is_age_based" value="0">
                        <input type="checkbox" name="is_age_based" value="1"
                            class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500"
                            x-model="ageBased"
                            @checked(old('is_age_based'))>
                        <span class="text-sm text-stone-700">Age-based category</span>
                    </label>
                    <p class="mt-1 text-xs text-stone-400">Enable to restrict this category to a specific age range.</p>
                </div>

                <div x-show="ageBased" x-transition class="sm:col-span-2 grid sm:grid-cols-2 gap-6">
                    <div>
                        <label for="min_age" class="block text-sm font-medium text-stone-700 mb-1">Minimum Age</label>
                        <input type="number" name="min_age" id="min_age" value="{{ old('min_age') }}" min="0" max="120"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Leave blank for no lower bound.</p>
                    </div>

                    <div>
                        <label for="max_age" class="block text-sm font-medium text-stone-700 mb-1">Maximum Age</label>
                        <input type="number" name="max_age" id="max_age" value="{{ old('max_age') }}" min="0" max="120"
                            class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="mt-1 text-xs text-stone-400">Leave blank for no upper bound.</p>
                    </div>
                </div>

                <div>
                    <label for="display_order" class="block text-sm font-medium text-stone-700 mb-1">Display Order</label>
                    <input type="number" name="display_order" id="display_order" value="{{ old('display_order', 0) }}" min="0" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Lower number = appears first.</p>
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <flux:button type="submit" variant="primary" class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Create Category
                </flux:button>
                <a href="{{ route('categories.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
