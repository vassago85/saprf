<x-layouts.app :title="'Edit Sponsor - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Edit Sponsor</h1>
                <p class="mt-1 text-sm text-stone-500">Update {{ $sponsor->name }}.</p>
            </div>
            <a href="{{ route('sponsors.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">← Back to Sponsors</a>
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

        <form method="POST" action="{{ route('sponsors.update', $sponsor) }}" enctype="multipart/form-data" class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf
            @method('PUT')

            <div class="grid sm:grid-cols-2 gap-6">
                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Sponsor Name</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $sponsor->name) }}" required
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="sponsor_tier_id" class="block text-sm font-medium text-stone-700 mb-1">Tier</label>
                    <select name="sponsor_tier_id" id="sponsor_tier_id" required
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        @foreach ($tiers as $tier)
                            <option value="{{ $tier->id }}" @selected(old('sponsor_tier_id', $sponsor->sponsor_tier_id) == $tier->id)>
                                {{ $tier->name }} (R{{ number_format($tier->price_per_year, 0) }}/yr)
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="website_url" class="block text-sm font-medium text-stone-700 mb-1">Website URL</label>
                    <input type="url" name="website_url" id="website_url" value="{{ old('website_url', $sponsor->website_url) }}" placeholder="https://..."
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div class="sm:col-span-2">
                    <label for="logo" class="block text-sm font-medium text-stone-700 mb-1">Logo</label>
                    @if ($sponsor->logoUrl())
                        <div class="mb-3 flex items-center gap-4">
                            <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}" class="h-12 w-auto rounded border border-stone-200 p-1">
                            <span class="text-xs text-stone-400">Current logo</span>
                        </div>
                    @endif
                    <input type="file" name="logo" id="logo" accept="image/*"
                        class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                    <p class="mt-1 text-xs text-stone-400">Leave empty to keep current logo.</p>
                </div>

                <div>
                    <label for="starts_at" class="block text-sm font-medium text-stone-700 mb-1">Start Date</label>
                    <input type="date" name="starts_at" id="starts_at" value="{{ old('starts_at', $sponsor->starts_at->format('Y-m-d')) }}" required
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="expires_at" class="block text-sm font-medium text-stone-700 mb-1">Expiry Date</label>
                    <input type="date" name="expires_at" id="expires_at" value="{{ old('expires_at', $sponsor->expires_at->format('Y-m-d')) }}" required
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="contact_name" class="block text-sm font-medium text-stone-700 mb-1">Contact Name</label>
                    <input type="text" name="contact_name" id="contact_name" value="{{ old('contact_name', $sponsor->contact_name) }}"
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="contact_email" class="block text-sm font-medium text-stone-700 mb-1">Contact Email</label>
                    <input type="email" name="contact_email" id="contact_email" value="{{ old('contact_email', $sponsor->contact_email) }}"
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div class="sm:col-span-2">
                    <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">Notes</label>
                    <textarea name="notes" id="notes" rows="3"
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">{{ old('notes', $sponsor->notes) }}</textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="inline-flex items-center gap-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" @checked(old('is_active', $sponsor->is_active))>
                        <span class="text-sm text-stone-700">Active</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button type="submit" class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Update Sponsor
                </button>
                <a href="{{ route('sponsors.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
