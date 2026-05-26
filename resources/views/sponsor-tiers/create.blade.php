<x-layouts.app :title="'Create Sponsor Tier - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Create Sponsor Tier</h1>
                <p class="mt-1 text-sm text-stone-500">Define a new sponsorship level.</p>
            </div>
            <a href="{{ route('sponsor-tiers.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">← Back to Tiers</a>
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

        <form method="POST" action="{{ route('sponsor-tiers.store') }}" class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf

            <div class="grid sm:grid-cols-2 gap-6">
                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Tier Name</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" required placeholder="e.g. Platinum, Gold, Silver"
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="price_per_year" class="block text-sm font-medium text-stone-700 mb-1">Price per Year (ZAR)</label>
                    <input type="number" name="price_per_year" id="price_per_year" value="{{ old('price_per_year', 0) }}" min="0" step="0.01" required
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="display_order" class="block text-sm font-medium text-stone-700 mb-1">Display Order</label>
                    <input type="number" name="display_order" id="display_order" value="{{ old('display_order', 0) }}" min="0" required
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Lower number = higher priority. 1 = top tier.</p>
                </div>

                <div>
                    <label for="logo_max_height" class="block text-sm font-medium text-stone-700 mb-1">Logo Max Height (px)</label>
                    <input type="number" name="logo_max_height" id="logo_max_height" value="{{ old('logo_max_height', 40) }}" min="16" max="200" required
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="mt-1 text-xs text-stone-400">Higher tiers should have larger logos (e.g. 80px for Platinum, 40px for Silver).</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-stone-700 mb-2">Placement</label>
                    <p class="text-xs text-stone-400 mb-3">Select where sponsors of this tier should appear.</p>
                    <div class="space-y-2">
                        @foreach ($placements as $key => $label)
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="placement[]" value="{{ $key }}"
                                    class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500"
                                    @checked(in_array($key, old('placement', [])))>
                                <span class="text-sm text-stone-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button type="submit" class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Create Tier
                </button>
                <a href="{{ route('sponsor-tiers.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
