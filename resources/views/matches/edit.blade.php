<x-layouts.app :title="'Edit: ' . $match->name">
    <h1 class="font-heading text-3xl font-bold text-stone-900">Edit Match</h1>
    <p class="mt-1 text-sm text-stone-500">{{ $match->name }}</p>

    <div class="mt-6 border-t border-stone-200"></div>

    <form method="POST" action="{{ route('matches.update', $match) }}" class="mt-6 max-w-3xl space-y-6">
        @csrf
        @method('PUT')

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Match Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="name" value="{{ old('name', $match->name) }}" required class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div>
                    <label for="match_type" class="block text-sm font-medium text-stone-700 mb-1">Match Type <span class="text-red-500">*</span></label>
                    <select name="match_type" id="match_type" required class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select type…</option>
                        <option value="PRS" @selected(old('match_type', $match->match_type) === 'PRS')>PRS</option>
                        <option value="PR22" @selected(old('match_type', $match->match_type) === 'PR22')>PR22</option>
                    </select>
                </div>

                <div>
                    <label for="series_level" class="block text-sm font-medium text-stone-700 mb-1">Series Level <span class="text-red-500">*</span></label>
                    <select name="series_level" id="series_level" required class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select level…</option>
                        <option value="national" @selected(old('series_level', $match->series_level) === 'national')>National</option>
                        <option value="provincial" @selected(old('series_level', $match->series_level) === 'provincial')>Provincial</option>
                        <option value="club" @selected(old('series_level', $match->series_level) === 'club')>Club</option>
                    </select>
                </div>

                <div>
                    <label for="province_id" class="block text-sm font-medium text-stone-700 mb-1">Province</label>
                    <select name="province_id" id="province_id" class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select province…</option>
                        @foreach ($provinces as $province)
                            <option value="{{ $province->id }}" @selected(old('province_id', $match->province_id) == $province->id)>{{ $province->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="match_date" class="block text-sm font-medium text-stone-700 mb-1">Match Date <span class="text-red-500">*</span></label>
                    <input type="date" name="match_date" id="match_date" value="{{ old('match_date', $match->match_date->format('Y-m-d')) }}" required class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div>
                    <label for="venue_name" class="block text-sm font-medium text-stone-700 mb-1">Venue Name</label>
                    <input type="text" name="venue_name" id="venue_name" value="{{ old('venue_name', $match->venue_name) }}" class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div>
                    <label for="venue_location" class="block text-sm font-medium text-stone-700 mb-1">Venue Location</label>
                    <input type="text" name="venue_location" id="venue_location" value="{{ old('venue_location', $match->venue_location) }}" class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div class="sm:col-span-2">
                    <label for="description" class="block text-sm font-medium text-stone-700 mb-1">Description</label>
                    <textarea name="description" id="description" rows="3" class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('description', $match->description) }}</textarea>
                </div>

                <div>
                    <label for="registration_opens_at" class="block text-sm font-medium text-stone-700 mb-1">Registration Opens</label>
                    <input type="datetime-local" name="registration_opens_at" id="registration_opens_at" value="{{ old('registration_opens_at', $match->registration_opens_at?->format('Y-m-d\TH:i')) }}" class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div>
                    <label for="registration_closes_at" class="block text-sm font-medium text-stone-700 mb-1">Registration Closes</label>
                    <input type="datetime-local" name="registration_closes_at" id="registration_closes_at" value="{{ old('registration_closes_at', $match->registration_closes_at?->format('Y-m-d\TH:i')) }}" class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div>
                    <label for="active_member_fee" class="block text-sm font-medium text-stone-700 mb-1">Match Entry Fee (ZAR) <span class="text-red-500">*</span></label>
                    <input type="number" name="active_member_fee" id="active_member_fee" step="0.01" value="{{ old('active_member_fee', $match->active_member_fee) }}" required class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div class="sm:col-span-2">
                    <p class="text-xs text-stone-500">Non-member and lapsed member surcharges are added automatically from <a href="{{ route('site-settings.index') }}" class="text-emerald-700 hover:underline">Site Settings</a>.</p>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-stone-700 mb-1">Status</label>
                    <select name="status" id="status" class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="draft" @selected(old('status', $match->status) === 'draft')>Draft</option>
                        <option value="open" @selected(old('status', $match->status) === 'open')>Open</option>
                        <option value="closed" @selected(old('status', $match->status) === 'closed')>Closed</option>
                        <option value="completed" @selected(old('status', $match->status) === 'completed')>Completed</option>
                    </select>
                </div>
            </div>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">Update Match</flux:button>
            <flux:button href="{{ route('matches.show', $match) }}" variant="ghost">Cancel</flux:button>
        </div>
    </form>
</x-layouts.app>
