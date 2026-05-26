<x-layouts.app :title="'Create Match'">
    <h1 class="font-heading text-3xl font-bold text-stone-900">Create Match</h1>

    <div class="mt-6 border-t border-stone-200"></div>

    <form method="POST" action="{{ route('matches.store') }}" class="mt-6 max-w-3xl space-y-6">
        @csrf

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2" x-data="{ matchType: '{{ old('match_type', '') }}' }">
                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Match Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" required class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div>
                    <label for="match_type" class="block text-sm font-medium text-stone-700 mb-1">Match Type <span class="text-red-500">*</span></label>
                    <select name="match_type" id="match_type" required x-model="matchType" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select type…</option>
                        <option value="PRS">PRS</option>
                        <option value="PR22">PR22</option>
                    </select>
                </div>

                <div>
                    <label for="series_level" class="block text-sm font-medium text-stone-700 mb-1">Series Level <span class="text-red-500">*</span></label>
                    <select name="series_level" id="series_level" required class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select level…</option>
                        <option value="national" @selected(old('series_level') === 'national')>National</option>
                        <option value="provincial" @selected(old('series_level') === 'provincial')>Provincial</option>
                        <option value="club" @selected(old('series_level') === 'club')>Club</option>
                        <option value="final" @selected(old('series_level') === 'final')>Final / Champs</option>
                    </select>
                    <p class="mt-1 text-xs text-stone-400">Finals attract the configured weighting multiplier in season standings.</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-stone-700 mb-2">Available Divisions</label>
                    <p class="text-xs text-stone-400 mb-3">Select which divisions shooters can compete in for this match.</p>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach(\App\Models\Division::active()->ordered()->get() as $division)
                            <label class="flex items-center gap-2 rounded-lg border border-stone-200 px-3 py-2 text-sm hover:bg-stone-50 cursor-pointer">
                                <input type="checkbox" name="divisions[]" value="{{ $division->id }}" 
                                    @checked(in_array($division->id, old('divisions', [])))
                                    class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                                <span>{{ $division->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label for="province_id" class="block text-sm font-medium text-stone-700 mb-1">Province</label>
                    <select name="province_id" id="province_id" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select province…</option>
                        @foreach ($provinces as $province)
                            <option value="{{ $province->id }}" @selected(old('province_id') == $province->id)>{{ $province->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="match_date" class="block text-sm font-medium text-stone-700 mb-1">Start Date <span class="text-red-500">*</span></label>
                    <input type="date" name="match_date" id="match_date" value="{{ old('match_date') }}" required class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div>
                    <label for="match_end_date" class="block text-sm font-medium text-stone-700 mb-1">End Date <span class="text-stone-400 font-normal">(multi-day)</span></label>
                    <input type="date" name="match_end_date" id="match_end_date" value="{{ old('match_end_date') }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                    <p class="mt-1 text-xs text-stone-400">Leave blank for single-day matches.</p>
                </div>

                @include('matches._venue-selector', ['currentVenueName' => old('venue_name'), 'currentCity' => old('city'), 'currentLocation' => old('venue_location'), 'currentProvinceId' => old('province_id')])

                <div class="sm:col-span-2">
                    <label for="description" class="block text-sm font-medium text-stone-700 mb-1">Description</label>
                    <textarea name="description" id="description" rows="3" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('description') }}</textarea>
                </div>

                <div>
                    <label for="registration_open_date" class="block text-sm font-medium text-stone-700 mb-1">Registration Opens</label>
                    <input type="date" name="registration_open_date" id="registration_open_date" value="{{ old('registration_open_date') }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div>
                    <label for="registration_close_date" class="block text-sm font-medium text-stone-700 mb-1">Registration Closes</label>
                    <input type="date" name="registration_close_date" id="registration_close_date" value="{{ old('registration_close_date') }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="active_member_fee" class="block text-sm font-medium text-stone-700 mb-1">Match Entry Fee (ZAR) <span class="text-red-500">*</span></label>
                        <input type="number" name="active_member_fee" id="active_member_fee" step="0.01" value="{{ old('active_member_fee') }}" required class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                    </div>
                    <div>
                        <label for="estimated_shooters" class="block text-sm font-medium text-stone-700 mb-1">Estimated Shooters</label>
                        <input type="number" name="estimated_shooters" id="estimated_shooters" min="1" max="999" value="{{ old('estimated_shooters') }}" placeholder="e.g. 30" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                        <p class="mt-1 text-xs text-stone-400">Used for per-shooter expense calculations and revenue projections.</p>
                    </div>
                </div>

                @include('matches._cost-estimator')

                <div class="sm:col-span-2 rounded-lg border border-stone-200 bg-stone-50/50 p-4">
                    <h3 class="text-sm font-semibold text-stone-700 mb-3">Capacity &amp; Waitlist</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="max_competitors" class="block text-sm font-medium text-stone-700 mb-1">Maximum Competitors</label>
                            <input type="number" name="max_competitors" id="max_competitors" min="1" max="999" value="{{ old('max_competitors') }}" placeholder="No limit" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                            <p class="mt-1 text-xs text-stone-400">Leave blank for unlimited entries.</p>
                        </div>
                        <div class="flex items-end">
                            <label class="inline-flex items-start gap-2 mb-1">
                                <input type="hidden" name="waitlist_enabled" value="0">
                                <input type="checkbox" name="waitlist_enabled" value="1" @checked(old('waitlist_enabled')) class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                                <span class="text-sm text-stone-700">
                                    Enable waitlist when full
                                    <span class="block text-xs font-normal text-stone-400 mt-0.5">Once capacity is reached, additional shooters can join a waitlist.</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-stone-700 mb-1">Status</label>
                    <select name="status" id="status" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="draft" @selected(old('status', 'draft') === 'draft')>Draft (not visible publicly)</option>
                        <option value="open" @selected(old('status') === 'open')>Open (registration available)</option>
                    </select>
                </div>

                <div class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="category_rankings_enabled" value="0">
                        <input type="checkbox" name="category_rankings_enabled" value="1" @checked(old('category_rankings_enabled')) class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm text-stone-700">Enable category rankings</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="division_awards_enabled" value="0">
                        <input type="checkbox" name="division_awards_enabled" value="1" @checked(old('division_awards_enabled')) class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm text-stone-700">Enable division awards</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="category_awards_enabled" value="0">
                        <input type="checkbox" name="category_awards_enabled" value="1" @checked(old('category_awards_enabled')) class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm text-stone-700">Enable category awards</span>
                    </label>
                </div>

                {{-- Provincial dual-count (national matches only) --}}
                <div class="sm:col-span-2" x-data="{ dualCount: {{ old('also_counts_for_provincial') ? 'true' : 'false' }} }">
                    <label class="flex items-center gap-2 mb-2">
                        <input type="hidden" name="also_counts_for_provincial" value="0">
                        <input type="checkbox" name="also_counts_for_provincial" value="1" x-model="dualCount" @checked(old('also_counts_for_provincial')) class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm font-medium text-stone-700">Day 1 stages also count as Provincial</span>
                    </label>
                    <p class="text-xs text-stone-400 mb-3">For 2-day nationals where Day 1 doubles as the provincial match. The full score counts for national standings; selected stages count for provincial.</p>

                    <div x-show="dualCount" x-cloak class="mt-3 p-4 rounded-lg bg-stone-50 border border-stone-200 space-y-3">
                        <div>
                            <label for="provincial_stage_columns" class="block text-sm font-medium text-stone-700 mb-1">Provincial Stage Columns</label>
                            <input type="text" name="provincial_stage_columns" id="provincial_stage_columns" value="{{ old('provincial_stage_columns') }}"
                                   placeholder="e.g. stage_1, stage_2, stage_3, stage_4, stage_5"
                                   class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                            <p class="mt-1.5 text-xs text-stone-400">Comma-separated CSV column names for the stages that count for provincial. These must match the column headers in the PractiScore/Impact export. The system will sum these values per shooter.</p>
                        </div>
                    </div>
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
            <flux:button type="submit" variant="primary">Create Match</flux:button>
            <flux:button href="{{ route('matches.index') }}" variant="ghost">Cancel</flux:button>
        </div>
    </form>
</x-layouts.app>
