<x-layouts.app :title="'Edit: ' . $match->name">
    <h1 class="font-heading text-3xl font-bold text-stone-900">Edit Match</h1>
    <p class="mt-1 text-sm text-stone-500">{{ $match->name }}</p>

    <div class="mt-6 border-t border-stone-200"></div>

    <form method="POST" action="{{ route('matches.update', $match) }}" class="mt-6 max-w-3xl space-y-6">
        @csrf
        @method('PUT')

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2" x-data="{ matchType: '{{ old('match_type', $match->match_type) }}' }">
                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Match Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="name" value="{{ old('name', $match->name) }}" required class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
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
                        <option value="national" @selected(old('series_level', $match->series_level) === 'national')>National</option>
                        <option value="provincial" @selected(old('series_level', $match->series_level) === 'provincial')>Provincial</option>
                        <option value="club" @selected(old('series_level', $match->series_level) === 'club')>Club</option>
                        <option value="final" @selected(old('series_level', $match->series_level) === 'final')>Final / Champs</option>
                        <option value="international" @selected(old('series_level', $match->series_level) === 'international')>International</option>
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
                                    @checked($match->divisions->contains($division->id) || in_array($division->id, old('divisions', [])))
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
                            <option value="{{ $province->id }}" @selected(old('province_id', $match->province_id) == $province->id)>{{ $province->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="match_date" class="block text-sm font-medium text-stone-700 mb-1">Start Date <span class="text-red-500">*</span></label>
                    <input type="date" name="match_date" id="match_date" value="{{ old('match_date', $match->match_date->format('Y-m-d')) }}" required class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div>
                    <label for="match_end_date" class="block text-sm font-medium text-stone-700 mb-1">End Date <span class="text-stone-400 font-normal">(multi-day)</span></label>
                    <input type="date" name="match_end_date" id="match_end_date" value="{{ old('match_end_date', $match->match_end_date?->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                    <p class="mt-1 text-xs text-stone-400">Leave blank for single-day matches.</p>
                </div>

                @include('matches._venue-selector', ['currentVenueName' => old('venue_name', $match->venue_name), 'currentCity' => old('city', $match->city), 'currentLocation' => old('venue_location', $match->venue_location), 'currentProvinceId' => old('province_id', $match->province_id)])

                <div>
                    <label for="match_director" class="block text-sm font-medium text-stone-700 mb-1">Match Director</label>
                    <input type="text" name="match_director" id="match_director" value="{{ old('match_director', $match->match_director) }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('match_director') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="match_director_contact" class="block text-sm font-medium text-stone-700 mb-1">MD Contact <span class="text-stone-400">(optional)</span></label>
                    <input type="text" name="match_director_contact" id="match_director_contact" value="{{ old('match_director_contact', $match->match_director_contact) }}" placeholder="Phone or email" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('match_director_contact') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="whatsapp_invite_url" class="block text-sm font-medium text-stone-700 mb-1">WhatsApp group invite <span class="text-stone-400">(optional)</span></label>
                    <input type="url" name="whatsapp_invite_url" id="whatsapp_invite_url" value="{{ old('whatsapp_invite_url', $match->whatsapp_invite_url) }}" placeholder="https://chat.whatsapp.com/…" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                    <p class="mt-1 text-xs text-stone-400">Shown to confirmed shooters after they no longer need to pay, so they can join for notifications and match books.</p>
                    @error('whatsapp_invite_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="description" class="block text-sm font-medium text-stone-700 mb-1">Description</label>
                    <textarea name="description" id="description" rows="3" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('description', $match->description) }}</textarea>
                </div>

                <div>
                    <label for="registration_open_date" class="block text-sm font-medium text-stone-700 mb-1">Registration Opens</label>
                    <input type="date" name="registration_open_date" id="registration_open_date" value="{{ old('registration_open_date', $match->registration_open_date?->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div>
                    <label for="registration_close_date" class="block text-sm font-medium text-stone-700 mb-1">Registration Closes</label>
                    <input type="date" name="registration_close_date" id="registration_close_date" value="{{ old('registration_close_date', $match->registration_close_date?->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="active_member_fee" class="block text-sm font-medium text-stone-700 mb-1">Match Entry Fee (ZAR) <span class="text-red-500">*</span></label>
                        <input type="number" name="active_member_fee" id="active_member_fee" step="0.01" value="{{ old('active_member_fee', $match->active_member_fee) }}" required class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                        <p class="mt-1 text-xs text-stone-400">Applies to <strong>new</strong> sign-ups only. Shooters already registered keep the price they paid, so payouts stay accurate.</p>
                    </div>
                    <div>
                        <label for="junior_fee" class="block text-sm font-medium text-stone-700 mb-1">Junior Fee (ZAR)</label>
                        <input type="number" name="junior_fee" id="junior_fee" step="0.01" min="0" value="{{ old('junior_fee', $match->junior_fee) }}" placeholder="Leave blank = same as entry fee" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                        <p class="mt-1 text-xs text-stone-400">Charged for Junior-division entries.</p>
                    </div>
                    <div>
                        <label for="estimated_shooters" class="block text-sm font-medium text-stone-700 mb-1">Estimated Shooters</label>
                        <input type="number" name="estimated_shooters" id="estimated_shooters" min="1" max="999" value="{{ old('estimated_shooters', $match->estimated_shooters) }}" placeholder="e.g. 30" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                        <p class="mt-1 text-xs text-stone-400">Drives the Cost Estimator below and per-shooter expense / revenue projections after saving.</p>
                    </div>
                </div>

                @include('matches._cost-estimator')

                {{-- Per-match fee overrides. Restricted to exco + developer
                     because touching these directly rewrites the split
                     between SAPRF, the platform operator, and the MD.
                     Owner + admin see the values but can't change them. --}}
                @php
                    $canEditFeeOverrides = auth()->user()?->hasAnyRole(['exco', 'developer']);
                    $canSeeFeeOverrides = auth()->user()?->hasAnyRole(['exco', 'developer', 'owner', 'admin']);
                    $globalPlatformType = $settings['platform_fee_type'] ?? 'fixed';
                    $globalPlatformValue = (float) ($settings['platform_fee_value'] ?? 0);
                    $globalSaprfType = $settings['saprf_fee_type'] ?? 'fixed';
                    $globalSaprfValue = (float) ($settings['saprf_fee_value'] ?? 50);
                    $formatGlobal = fn ($type, $value) => $type === 'fixed'
                        ? 'R ' . number_format((float) $value, 2) . ' per shooter'
                        : rtrim(rtrim(number_format((float) $value, 2), '0'), '.') . '% of match fee';
                @endphp
                @if($canSeeFeeOverrides)
                <div class="sm:col-span-2 rounded-lg border border-violet-200 bg-violet-50/40 p-4">
                    <div class="flex items-center justify-between mb-2 gap-3 flex-wrap">
                        <div>
                            <h3 class="text-sm font-semibold text-violet-900">Fee Overrides</h3>
                            <p class="mt-0.5 text-xs text-violet-700">
                                Leave blank to inherit the global rate.
                                Override to charge a different SAPRF or platform fee for this match — e.g. R0 for matches that don't run through the platform.
                            </p>
                        </div>
                        <span class="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold text-violet-800 ring-1 ring-inset ring-violet-300">
                            {{ $canEditFeeOverrides ? 'Exco / Developer' : 'Read-only' }}
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- Platform Fee override --}}
                        <div x-data="{
                                type: '{{ old('platform_fee_type', $match->platform_fee_type ?? '') }}',
                                value: '{{ old('platform_fee_value', $match->platform_fee_value ?? '') }}',
                            }">
                            <label class="block text-xs font-medium text-stone-600 mb-1">Platform Fee override</label>
                            <div class="flex gap-2">
                                <select name="platform_fee_type" x-model="type" @change="if (!type) value = ''"
                                        @disabled(!$canEditFeeOverrides)
                                        class="rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-stone-100 disabled:text-stone-500">
                                    <option value="">Inherit ({{ $formatGlobal($globalPlatformType, $globalPlatformValue) }})</option>
                                    <option value="fixed">R fixed / shooter</option>
                                    <option value="percentage">% of match fee</option>
                                </select>
                                <div class="relative flex-1">
                                    <input type="number" name="platform_fee_value" step="0.01" min="0" x-model="value"
                                           :required="!!type"
                                           :disabled="!type || {{ $canEditFeeOverrides ? 'false' : 'true' }}"
                                           placeholder="—"
                                           class="block w-full rounded-lg border border-stone-300 text-sm py-2 pr-8 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-stone-100 disabled:text-stone-400">
                                    <span class="absolute inset-y-0 right-3 flex items-center text-xs font-semibold text-stone-400" x-text="type === 'percentage' ? '%' : (type === 'fixed' ? 'R' : '')"></span>
                                </div>
                            </div>
                            @error('platform_fee_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            @error('platform_fee_value') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        {{-- SAPRF Fee override --}}
                        <div x-data="{
                                type: '{{ old('saprf_fee_type', $match->saprf_fee_type ?? '') }}',
                                value: '{{ old('saprf_fee_value', $match->saprf_fee_value ?? '') }}',
                            }">
                            <label class="block text-xs font-medium text-stone-600 mb-1">SAPRF Fee override</label>
                            <div class="flex gap-2">
                                <select name="saprf_fee_type" x-model="type" @change="if (!type) value = ''"
                                        @disabled(!$canEditFeeOverrides)
                                        class="rounded-lg border border-stone-300 text-sm py-2 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-stone-100 disabled:text-stone-500">
                                    <option value="">Inherit ({{ $formatGlobal($globalSaprfType, $globalSaprfValue) }})</option>
                                    <option value="fixed">R fixed / shooter</option>
                                    <option value="percentage">% of match fee</option>
                                </select>
                                <div class="relative flex-1">
                                    <input type="number" name="saprf_fee_value" step="0.01" min="0" x-model="value"
                                           :required="!!type"
                                           :disabled="!type || {{ $canEditFeeOverrides ? 'false' : 'true' }}"
                                           placeholder="—"
                                           class="block w-full rounded-lg border border-stone-300 text-sm py-2 pr-8 focus:ring-emerald-500 focus:border-emerald-500 disabled:bg-stone-100 disabled:text-stone-400">
                                    <span class="absolute inset-y-0 right-3 flex items-center text-xs font-semibold text-stone-400" x-text="type === 'percentage' ? '%' : (type === 'fixed' ? 'R' : '')"></span>
                                </div>
                            </div>
                            @error('saprf_fee_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            @error('saprf_fee_value') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
                @endif

                <div class="sm:col-span-2 rounded-lg border border-stone-200 bg-stone-50/50 p-4">
                    <h3 class="text-sm font-semibold text-stone-700 mb-3">Capacity &amp; Waitlist</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="max_competitors" class="block text-sm font-medium text-stone-700 mb-1">Maximum Competitors</label>
                            <input type="number" name="max_competitors" id="max_competitors" min="1" max="999" value="{{ old('max_competitors', $match->max_competitors) }}" placeholder="No limit" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                            <p class="mt-1 text-xs text-stone-400">Leave blank for unlimited entries.</p>
                        </div>
                        <div class="flex items-end">
                            <label class="inline-flex items-start gap-2 mb-1">
                                <input type="hidden" name="waitlist_enabled" value="0">
                                <input type="checkbox" name="waitlist_enabled" value="1" @checked(old('waitlist_enabled', $match->waitlist_enabled)) class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
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
                        <option value="draft" @selected(old('status', $match->status) === 'draft')>Draft (not visible publicly)</option>
                        <option value="open" @selected(old('status', $match->status) === 'open')>Open (registration available)</option>
                        <option value="closed" @selected(old('status', $match->status) === 'closed')>Closed (registration shut)</option>
                        <option value="completed" @selected(old('status', $match->status) === 'completed')>Completed</option>
                        <option value="cancelled" @selected(old('status', $match->status) === 'cancelled')>Cancelled</option>
                    </select>
                </div>

                @role('owner|admin|exco|developer')
                    <div class="sm:col-span-2 rounded-lg border border-stone-200 bg-stone-50/50 p-4">
                        <label class="flex items-start gap-2.5">
                            <input type="hidden" name="everyone_counts" value="0">
                            <input type="checkbox" name="everyone_counts" value="1" @checked(old('everyone_counts', $match->everyone_counts)) class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <span>
                                <span class="block text-sm font-medium text-stone-800">All shooters count regardless of membership</span>
                                <span class="mt-1 block text-xs text-stone-500">Use for imported historical matches (e.g. Day-1 provincial extracts) where the organiser ruled everyone counts. Every score on this match evaluates to Eligible on save/re-import/re-evaluation, instead of running the usual membership check.</span>
                            </span>
                        </label>
                    </div>
                @endrole

                {{-- Provincial dual-count (national matches only) --}}
                <div class="sm:col-span-2" x-data="{ dualCount: {{ old('also_counts_for_provincial', $match->also_counts_for_provincial) ? 'true' : 'false' }} }">
                    <label class="flex items-center gap-2 mb-2">
                        <input type="hidden" name="also_counts_for_provincial" value="0">
                        <input type="checkbox" name="also_counts_for_provincial" value="1" x-model="dualCount" @checked(old('also_counts_for_provincial', $match->also_counts_for_provincial)) class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm font-medium text-stone-700">Day 1 also counts as a Provincial score</span>
                    </label>
                    <p class="text-xs text-stone-400 mb-3">Recommended for all 2-day nationals. Under PR22 pooled scoring, each shooter's <strong>Day 1 total</strong> is used as a provincial-pool contribution (in addition to counting Day 1 + Day 2 for the national pool). MDs enter Day 1 and Day 2 separately when scoring.</p>

                    <div x-show="dualCount" x-cloak class="mt-3 p-4 rounded-lg bg-stone-50 border border-stone-200 space-y-3">
                        <div>
                            <label for="provincial_stage_columns" class="block text-sm font-medium text-stone-700 mb-1">Provincial Stage Columns</label>
                            <input type="text" name="provincial_stage_columns" id="provincial_stage_columns" value="{{ old('provincial_stage_columns', $match->provincial_stage_columns) }}"
                                   placeholder="e.g. stage_1, stage_2, stage_3, stage_4, stage_5"
                                   class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
                            <p class="mt-1.5 text-xs text-stone-400">Comma-separated CSV column names for the stages that count for provincial. These must match the column headers in the PractiScore/Impact export. The system will sum these values per shooter.</p>
                        </div>

                        @if($match->scores()->whereNotNull('raw_meta')->exists())
                            @php
                                $sampleMeta = $match->scores()->whereNotNull('raw_meta')->first()?->raw_meta;
                                $stageKeys = $sampleMeta ? collect(array_keys($sampleMeta))->filter(fn($k) => preg_match('/stage|day/i', $k))->values() : collect();
                            @endphp
                            @if($stageKeys->isNotEmpty())
                                <div class="rounded-lg bg-white border border-stone-200 p-3">
                                    <p class="text-xs font-medium text-stone-500 mb-1.5">Detected stage columns from imported scores:</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($stageKeys as $key)
                                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-mono text-emerald-700 ring-1 ring-inset ring-emerald-200">{{ $key }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif
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
            <flux:button type="submit" variant="primary">Update Match</flux:button>
            <flux:button href="{{ route('matches.show', $match) }}" variant="ghost">Cancel</flux:button>
        </div>
    </form>

    {{--
        Add-shooter panel. Lives on this page so the MD can seed off-platform
        entries (cash, EFT, comp'd) without leaving match settings. Every
        entry created here is booked confirmed + paid and never touches
        PayFast; if the shooter's membership is lapsed the MD can waive the
        surcharge for this one entry provided they type a reason (persisted
        to `fee_override_reason`). Sits in its OWN form so submitting it
        can't accidentally save unrelated changes on the settings form above.
    --}}
    @php
        $availableDivisionsForAdd = $match->availableDivisions();
    @endphp
    <div class="mt-10 rounded-xl border border-emerald-200 bg-emerald-50/40 shadow-sm p-6"
         id="add-shooter"
         x-data="adminAddShooter({
            searchUrl: '{{ route('events.members.search', $match) }}',
         })"
         x-init="init()">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-stone-900">Add a Shooter</h2>
                <p class="mt-1 text-sm text-stone-600">
                    Book a shooter into this match manually — for entries paid off-platform
                    (cash, EFT, comp'd). Confirmed and marked paid on save; no PayFast charge.
                </p>
            </div>
            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-300">
                MD / Admin
            </span>
        </div>

        {{-- Search box: reuses the sponsor-flow endpoint so results include
             each shooter's current entry state on this match. --}}
        <div class="mt-4 relative">
            <input type="search"
                   x-model.debounce.300ms="query"
                   @input="search()"
                   placeholder="Search by name or SAPRF number (min 2 characters)"
                   class="w-full rounded-lg border border-stone-300 bg-white text-sm py-2.5 pr-9 focus:ring-emerald-500 focus:border-emerald-500" />
            <div x-show="loading" x-cloak class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-stone-400">…</div>
        </div>

        <div x-show="results.length > 0 && !selected && !showNewShooter" x-cloak class="mt-3 space-y-2">
            <template x-for="r in results" :key="r.id">
                <div class="flex items-center justify-between gap-2 rounded-lg border border-stone-200 bg-white p-2.5">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-stone-900" x-text="r.name"></p>
                        <p class="text-[11px] text-stone-500">
                            <span x-text="r.saprf_number ? 'SAPRF ' + r.saprf_number : 'No SAPRF number'"></span>
                            <template x-if="r.province"><span> · <span x-text="r.province"></span></span></template>
                        </p>
                    </div>
                    <div class="shrink-0">
                        <template x-if="r.entry_state === 'none' || r.entry_state === 'cancelled'">
                            <button type="button"
                                    @click="pick(r)"
                                    class="px-3 py-1.5 rounded-lg bg-emerald-700 text-white text-xs font-semibold hover:bg-emerald-800 transition">
                                Select
                            </button>
                        </template>
                        <template x-if="r.entry_state === 'unpaid'">
                            <span class="text-[11px] text-amber-700 font-semibold">Unpaid entry exists</span>
                        </template>
                        <template x-if="r.entry_state === 'paid'">
                            <span class="text-[11px] text-emerald-700 font-semibold">Already entered</span>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <p x-show="!loading && query.length >= 2 && results.length === 0 && !selected && !showNewShooter" x-cloak
           class="mt-3 text-xs text-stone-500">No matching members found.</p>

        {{-- Escape hatch: shooter isn't on the platform yet. Provisions a
             stub account so pricing treats them as a non-member and future
             searches will find them. --}}
        <div class="mt-3 pt-3 border-t border-emerald-100"
             x-show="!selected && !showNewShooter" x-cloak>
            <button type="button"
                    @click="startNewShooter()"
                    class="text-xs font-medium text-emerald-700 hover:text-emerald-800">
                Can't find them? Add a shooter who isn't on the platform →
            </button>
        </div>

        {{-- Booking form appears when a shooter is picked (existing) OR the
             new-shooter mini-form is engaged. Submits its own POST so the
             settings form above is untouched. --}}
        <template x-if="selected || showNewShooter">
            <form method="POST" action="{{ route('matches.entries.store', $match) }}" class="mt-4 space-y-4">
                @csrf
                <template x-if="selected">
                    <input type="hidden" name="user_id" :value="selected.id" />
                </template>
                <template x-if="showNewShooter">
                    <div class="contents">
                        <input type="hidden" name="new_shooter_name" :value="newShooterName" />
                        <input type="hidden" name="new_shooter_email" :value="newShooterEmail" />
                    </div>
                </template>

                <template x-if="selected">
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-stone-200 bg-white p-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-stone-900" x-text="selected.name"></p>
                            <p class="text-[11px] text-stone-500">
                                <span x-text="selected.saprf_number ? 'SAPRF ' + selected.saprf_number : 'No SAPRF number'"></span>
                                <template x-if="selected.province"><span> · <span x-text="selected.province"></span></span></template>
                            </p>
                        </div>
                        <button type="button" @click="clear()"
                                class="text-xs font-medium text-stone-500 hover:text-stone-700">Change shooter</button>
                    </div>
                </template>

                <template x-if="showNewShooter">
                    <div class="rounded-lg border border-emerald-200 bg-white p-3 space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-semibold text-stone-900">Adding a new shooter</p>
                            <button type="button" @click="clear()"
                                    class="text-xs font-medium text-stone-500 hover:text-stone-700">Cancel</button>
                        </div>
                        <p class="text-[11px] text-stone-500">
                            An unclaimed account is created for them. If you provide an email they can claim it later via Forgot Password.
                        </p>
                        <div>
                            <label for="admin_new_shooter_name" class="block text-xs font-medium text-stone-600 mb-1">Shooter's full name <span class="text-red-500">*</span></label>
                            <input type="text" id="admin_new_shooter_name" x-model.trim="newShooterName"
                                   required minlength="2" maxlength="100"
                                   placeholder="e.g. Jane Doe"
                                   class="w-full rounded-lg border border-stone-300 bg-white text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500" />
                        </div>
                        <div>
                            <label for="admin_new_shooter_email" class="block text-xs font-medium text-stone-600 mb-1">Shooter's email <span class="text-stone-400 font-normal">(optional)</span></label>
                            <input type="email" id="admin_new_shooter_email" x-model.trim="newShooterEmail" maxlength="150"
                                   placeholder="jane@example.com"
                                   class="w-full rounded-lg border border-stone-300 bg-white text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500" />
                        </div>
                    </div>
                </template>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="add_division_id" class="block text-sm font-medium text-stone-700 mb-1">Division <span class="text-red-500">*</span></label>
                        <select name="division_id" id="add_division_id" required
                                class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="" disabled selected>— Select division —</option>
                            @foreach($availableDivisionsForAdd as $division)
                                <option value="{{ $division->id }}">{{ $division->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Waive-lapsed toggle. Reveals a required reason field so
                     the audit trail on the entry row explains why the shooter
                     did not pay the surcharge their membership implied. --}}
                <div class="rounded-lg border border-amber-200 bg-amber-50/60 p-3"
                     x-data="{ waive: false }">
                    <label class="flex items-start gap-2 text-sm text-stone-700">
                        <input type="hidden" name="waive_lapsed_surcharge" value="0">
                        <input type="checkbox" name="waive_lapsed_surcharge" value="1" x-model="waive"
                               class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span>
                            <span class="block font-medium">Waive lapsed-member surcharge</span>
                            <span class="mt-0.5 block text-xs text-stone-500">Applies only when this shooter's membership is expired. Ignored for active members and non-members.</span>
                        </span>
                    </label>
                    <div x-show="waive" x-cloak class="mt-3">
                        <label for="fee_override_reason" class="block text-xs font-medium text-stone-600 mb-1">Reason <span class="text-red-500">*</span></label>
                        <textarea name="fee_override_reason" id="fee_override_reason" rows="2" maxlength="500"
                                  :required="waive"
                                  placeholder="e.g. Paid R550 direct to MD in cash; grace on lapsed renewal for this match."
                                  class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-amber-500 focus:border-amber-500"></textarea>
                        <p class="mt-1 text-[11px] text-stone-500">Stored on the entry so future auditors can see why the surcharge was waived.</p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-1">
                    <button type="button" @click="clear()"
                            class="text-sm text-stone-500 hover:text-stone-700">Cancel</button>
                    <button type="submit"
                            :disabled="showNewShooter && newShooterName.trim().length < 2"
                            class="px-4 py-2 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800 disabled:opacity-40 disabled:cursor-not-allowed transition shadow-sm">
                        Add &amp; Confirm as Paid
                    </button>
                </div>
            </form>
        </template>

        {{-- Flashed status from the POST handler (success / info / errors). --}}
        @if(session('success'))
            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                {{ session('success') }}
            </div>
        @endif
        @if(session('info'))
            <div class="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-800">
                {{ session('info') }}
            </div>
        @endif
        @if($errors->hasAny(['user_id', 'division_id', 'fee_override_reason', 'new_shooter_name', 'new_shooter_email']))
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                <ul class="list-inside list-disc space-y-1">
                    @foreach(['user_id', 'new_shooter_name', 'new_shooter_email', 'division_id', 'fee_override_reason'] as $field)
                        @foreach($errors->get($field) as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('adminAddShooter', (config) => ({
                query: '',
                results: [],
                loading: false,
                selected: null,
                showNewShooter: false,
                newShooterName: '',
                newShooterEmail: '',
                searchUrl: config.searchUrl,
                _abort: null,
                init() {},
                search() {
                    if (this.query.trim().length < 2) {
                        this.results = [];
                        return;
                    }
                    if (this._abort) { this._abort.abort(); }
                    this._abort = new AbortController();
                    this.loading = true;
                    fetch(this.searchUrl + '?q=' + encodeURIComponent(this.query.trim()), {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        signal: this._abort.signal,
                    })
                        .then(r => r.ok ? r.json() : Promise.reject(r))
                        .then(data => { this.results = data.results || []; })
                        .catch(() => { /* ignored: transient network / abort */ })
                        .finally(() => { this.loading = false; });
                },
                pick(member) {
                    this.selected = member;
                    this.showNewShooter = false;
                    this.results = [];
                },
                startNewShooter() {
                    this.showNewShooter = true;
                    // Pre-fill the name from whatever they typed in the
                    // search box — most MDs will have typed the person's
                    // name already before realising they're not there.
                    if (this.query.trim().length >= 2 && this.newShooterName === '') {
                        this.newShooterName = this.query.trim();
                    }
                    this.results = [];
                },
                clear() {
                    this.selected = null;
                    this.showNewShooter = false;
                    this.newShooterName = '';
                    this.newShooterEmail = '';
                    this.query = '';
                    this.results = [];
                },
            }));
        });
    </script>
</x-layouts.app>
