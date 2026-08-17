@php($tier = $tier ?? null)

<div class="grid sm:grid-cols-2 gap-6">
    <div class="sm:col-span-2">
        <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Fee Name</label>
        <input type="text" name="name" id="name" value="{{ old('name', $tier?->name) }}" required placeholder="e.g. Adult, Senior, Military / Law Enforcement Officer"
            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div>
        <label for="price" class="block text-sm font-medium text-stone-700 mb-1">Amount (ZAR)</label>
        <input type="number" name="price" id="price" step="0.01" min="0" value="{{ old('price', $tier ? number_format((float) $tier->price, 2, '.', '') : '') }}" required placeholder="e.g. 850.00"
            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
        <p class="mt-1 text-xs text-stone-400">Charged for both application and renewal.</p>
    </div>

    <div>
        <label for="duration_months" class="block text-sm font-medium text-stone-700 mb-1">Term (months)</label>
        <input type="number" name="duration_months" id="duration_months" min="1" max="120" value="{{ old('duration_months', $tier?->duration_months ?? 12) }}" required
            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
        <p class="mt-1 text-xs text-stone-400">12 = one year.</p>
    </div>

    <div>
        <label for="display_order" class="block text-sm font-medium text-stone-700 mb-1">Display Order</label>
        <input type="number" name="display_order" id="display_order" min="0" value="{{ old('display_order', $tier?->display_order ?? 0) }}" required
            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
        <p class="mt-1 text-xs text-stone-400">Lower number = appears first.</p>
    </div>

    <div>
        <label for="slug" class="block text-sm font-medium text-stone-700 mb-1">Slug <span class="text-stone-400 font-normal">(optional)</span></label>
        <input type="text" name="slug" id="slug" value="{{ old('slug', $tier?->slug) }}" placeholder="auto-generated from name"
            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
        <p class="mt-1 text-xs text-stone-400">Unique identifier. Leave blank to generate from the name.</p>
    </div>

    <div class="sm:col-span-2">
        <label for="description" class="block text-sm font-medium text-stone-700 mb-1">Description <span class="text-stone-400 font-normal">(optional)</span></label>
        <textarea name="description" id="description" rows="2" placeholder="Shown to members on the join page…"
            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">{{ old('description', $tier?->description) }}</textarea>
    </div>

    <div>
        <label for="min_age" class="block text-sm font-medium text-stone-700 mb-1">Minimum age <span class="text-stone-400 font-normal">(optional)</span></label>
        <input type="number" name="min_age" id="min_age" min="0" max="120" value="{{ old('min_age', $tier?->min_age) }}" placeholder="e.g. 65 for Senior"
            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
        <p class="mt-1 text-xs text-stone-400">Blank = no lower age limit.</p>
    </div>

    <div>
        <label for="max_age" class="block text-sm font-medium text-stone-700 mb-1">Maximum age <span class="text-stone-400 font-normal">(optional)</span></label>
        <input type="number" name="max_age" id="max_age" min="0" max="120" value="{{ old('max_age', $tier?->max_age) }}" placeholder="e.g. 17 for Junior"
            class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
        <p class="mt-1 text-xs text-stone-400">Blank = no upper age limit.</p>
    </div>

    <div class="sm:col-span-2 flex flex-col gap-3 pt-1">
        <label class="inline-flex items-center gap-2">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1"
                class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500"
                @checked(old('is_active', $tier?->is_active ?? true))>
            <span class="text-sm text-stone-700">Active</span>
        </label>
        <p class="-mt-2 ml-6 text-xs text-stone-400">Uncheck to archive. Archived fees are hidden from members but kept for reporting.</p>

        <label class="inline-flex items-center gap-2">
            <input type="hidden" name="is_default" value="0">
            <input type="checkbox" name="is_default" value="1"
                class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500"
                @checked(old('is_default', $tier?->is_default ?? false))>
            <span class="text-sm text-stone-700">Default fee</span>
        </label>
        <p class="-mt-2 ml-6 text-xs text-stone-400">Pre-selected when a member joins. Only one fee can be the default.</p>
    </div>
</div>
