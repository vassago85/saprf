<x-layouts.app :title="'Add Sponsor - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Add Sponsor</h1>
                <p class="mt-1 text-sm text-stone-500">Create a new sponsor partnership.</p>
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

        <form method="POST" action="{{ route('sponsors.store') }}" enctype="multipart/form-data" class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf

            <div class="grid sm:grid-cols-2 gap-6">
                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Sponsor Name</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="sponsor_tier_id" class="block text-sm font-medium text-stone-700 mb-1">Tier</label>
                    <select name="sponsor_tier_id" id="sponsor_tier_id" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select tier...</option>
                        @foreach ($tiers as $tier)
                            <option value="{{ $tier->id }}" @selected(old('sponsor_tier_id') == $tier->id)>
                                {{ $tier->name }} (R{{ number_format($tier->price_per_year, 0) }}/yr)
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="website_url" class="block text-sm font-medium text-stone-700 mb-1">Website URL</label>
                    <input type="url" name="website_url" id="website_url" value="{{ old('website_url') }}" placeholder="https://..."
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div class="sm:col-span-2">
                    <label for="logo" class="block text-sm font-medium text-stone-700 mb-1">Logo</label>
                    <input type="file" name="logo" id="logo" accept="image/*" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2 px-3 file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                    <p class="mt-1 text-xs text-stone-400">PNG, JPG, SVG, or WebP. Max 2MB.</p>
                </div>

                <div>
                    <label for="starts_at" class="block text-sm font-medium text-stone-700 mb-1">Start Date</label>
                    <input type="date" name="starts_at" id="starts_at" value="{{ old('starts_at', now()->format('Y-m-d')) }}" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="expires_at" class="block text-sm font-medium text-stone-700 mb-1">Expiry Date</label>
                    <input type="date" name="expires_at" id="expires_at" value="{{ old('expires_at', now()->addYear()->format('Y-m-d')) }}" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="contact_name" class="block text-sm font-medium text-stone-700 mb-1">Contact Name</label>
                    <input type="text" name="contact_name" id="contact_name" value="{{ old('contact_name') }}"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label for="contact_email" class="block text-sm font-medium text-stone-700 mb-1">Contact Email</label>
                    <input type="email" name="contact_email" id="contact_email" value="{{ old('contact_email') }}"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div class="sm:col-span-2">
                    <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">Notes</label>
                    <textarea name="notes" id="notes" rows="3"
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">{{ old('notes') }}</textarea>
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button type="submit" class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Create Sponsor
                </button>
                <a href="{{ route('sponsors.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
