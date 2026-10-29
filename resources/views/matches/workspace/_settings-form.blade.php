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

@if($lockedByPaidEntries)
    <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        <flux:icon.lock-closed class="mt-0.5 size-4 shrink-0 text-amber-600" />
        <div>
            <p class="font-semibold">Fees &amp; date are locked</p>
            <p class="mt-0.5 text-xs">
                {{ $counts['paid'] }} paid {{ \Illuminate\Support\Str::plural('entry', $counts['paid']) }}
                already settled on this match. Changing the fee or date now would misalign payouts
                with what shooters paid. New sign-ups use whatever value is saved; existing entries
                keep their original price.
            </p>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('matches.update', $match) }}" enctype="multipart/form-data" class="space-y-4">
    @csrf
    @method('PUT')

    {{-- Match basics --}}
    <div class="rounded-xl border border-stone-200 bg-white p-5" x-data="{ matchType: '{{ old('match_type', $match->match_type) }}' }">
        <h3 class="text-sm font-semibold text-stone-900">Match basics</h3>
        <p class="mt-0.5 text-xs text-stone-500">Headline details that identify the match on the public listing.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="name" class="block text-xs font-medium text-stone-600 mb-1">Match name <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="name" value="{{ old('name', $match->name) }}" required class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            </div>

            <div>
                <label for="match_type" class="block text-xs font-medium text-stone-600 mb-1">Match type <span class="text-red-500">*</span></label>
                <select name="match_type" id="match_type" required x-model="matchType" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">Select type…</option>
                    <option value="PRS">PRS</option>
                    <option value="PR22">PR22</option>
                </select>
            </div>

            <div>
                <label for="series_level" class="block text-xs font-medium text-stone-600 mb-1">Series level <span class="text-red-500">*</span></label>
                <select name="series_level" id="series_level" required class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
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
                <label class="block text-xs font-medium text-stone-600 mb-1">Divisions on offer</label>
                <p class="text-xs text-stone-400 mb-2">Shooters can only enter the divisions you tick.</p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach(\App\Models\Division::active()->ordered()->get() as $division)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-stone-200 px-3 py-2 text-sm hover:bg-stone-50">
                            <input type="checkbox" name="divisions[]" value="{{ $division->id }}"
                                @checked($match->divisions->contains($division->id) || in_array($division->id, old('divisions', [])))
                                class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                            <span>{{ $division->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="sm:col-span-2">
                <label for="description" class="block text-xs font-medium text-stone-600 mb-1">Description</label>
                <textarea name="description" id="description" rows="3" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('description', $match->description) }}</textarea>
            </div>
        </div>
    </div>

    {{-- When & where --}}
    <div class="rounded-xl border border-stone-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-stone-900">When &amp; where</h3>
        <p class="mt-0.5 text-xs text-stone-500">Match day(s) and venue.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="match_date" class="block text-xs font-medium text-stone-600 mb-1">
                    Start date <span class="text-red-500">*</span>
                    @if($lockedByPaidEntries)
                        <span class="ml-1 text-[10px] font-semibold uppercase text-amber-700">Locked</span>
                    @endif
                </label>
                <input type="date" name="match_date" id="match_date" value="{{ old('match_date', $match->match_date->format('Y-m-d')) }}" required class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            </div>

            <div>
                <label for="match_end_date" class="block text-xs font-medium text-stone-600 mb-1">End date <span class="text-stone-400 font-normal">(multi-day)</span></label>
                <input type="date" name="match_end_date" id="match_end_date" value="{{ old('match_end_date', $match->match_end_date?->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                <p class="mt-1 text-xs text-stone-400">Leave blank for single-day matches.</p>
            </div>

            <div>
                <label for="province_id" class="block text-xs font-medium text-stone-600 mb-1">Province</label>
                <select name="province_id" id="province_id" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">Select province…</option>
                    @foreach ($provinces as $province)
                        <option value="{{ $province->id }}" @selected(old('province_id', $match->province_id) == $province->id)>{{ $province->name }}</option>
                    @endforeach
                </select>
            </div>

            @include('matches._venue-selector', ['currentVenueName' => old('venue_name', $match->venue_name), 'currentCity' => old('city', $match->city), 'currentLocation' => old('venue_location', $match->venue_location), 'currentProvinceId' => old('province_id', $match->province_id)])
        </div>
    </div>

    {{-- Director & contact --}}
    <div class="rounded-xl border border-stone-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-stone-900">Director &amp; contact</h3>
        <p class="mt-0.5 text-xs text-stone-500">Who runs the match and how shooters reach them.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="match_director" class="block text-xs font-medium text-stone-600 mb-1">Match director</label>
                <input type="text" name="match_director" id="match_director" value="{{ old('match_director', $match->match_director) }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                @error('match_director') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="match_director_contact" class="block text-xs font-medium text-stone-600 mb-1">MD contact <span class="text-stone-400 font-normal">(optional)</span></label>
                <input type="text" name="match_director_contact" id="match_director_contact" value="{{ old('match_director_contact', $match->match_director_contact) }}" placeholder="Phone or email" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                @error('match_director_contact') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="whatsapp_invite_url" class="block text-xs font-medium text-stone-600 mb-1">WhatsApp group invite <span class="text-stone-400 font-normal">(optional)</span></label>
                <input type="url" name="whatsapp_invite_url" id="whatsapp_invite_url" value="{{ old('whatsapp_invite_url', $match->whatsapp_invite_url) }}" placeholder="https://chat.whatsapp.com/…" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                <p class="mt-1 text-xs text-stone-400">Shown to confirmed shooters after they no longer need to pay, so they can join for notifications and match books.</p>
                @error('whatsapp_invite_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- Artwork --}}
    <div class="rounded-xl border border-stone-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-stone-900">Artwork</h3>
        <p class="mt-0.5 text-xs text-stone-500">Banner image for the listing card and full flyer on the match page.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @include('matches._media-fields', ['match' => $match])
        </div>
    </div>

    {{-- Registration & fees --}}
    <div class="rounded-xl border border-stone-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-stone-900">Registration &amp; fees</h3>
        <p class="mt-0.5 text-xs text-stone-500">When registration is open and what shooters are charged.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="registration_open_date" class="block text-xs font-medium text-stone-600 mb-1">Registration opens</label>
                <input type="date" name="registration_open_date" id="registration_open_date" value="{{ old('registration_open_date', $match->registration_open_date?->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            </div>

            <div>
                <label for="registration_close_date" class="block text-xs font-medium text-stone-600 mb-1">Registration closes</label>
                <input type="date" name="registration_close_date" id="registration_close_date" value="{{ old('registration_close_date', $match->registration_close_date?->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            </div>

            <div class="sm:col-span-2 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label for="active_member_fee" class="block text-xs font-medium text-stone-600 mb-1">
                        Entry fee (ZAR) <span class="text-red-500">*</span>
                        @if($lockedByPaidEntries)
                            <span class="ml-1 text-[10px] font-semibold uppercase text-amber-700">Locked</span>
                        @endif
                    </label>
                    <input type="number" name="active_member_fee" id="active_member_fee" step="0.01" value="{{ old('active_member_fee', $match->active_member_fee) }}" required class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-stone-400">Applies to <strong>new</strong> sign-ups only. Already-registered shooters keep what they paid.</p>
                </div>
                <div>
                    <label for="junior_fee" class="block text-xs font-medium text-stone-600 mb-1">Junior fee (ZAR)</label>
                    <input type="number" name="junior_fee" id="junior_fee" step="0.01" min="0" value="{{ old('junior_fee', $match->junior_fee) }}" placeholder="Blank = same as entry fee" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-stone-400">Charged for Junior-division entries.</p>
                </div>
                <div>
                    <label for="estimated_shooters" class="block text-xs font-medium text-stone-600 mb-1">Estimated shooters</label>
                    <input type="number" name="estimated_shooters" id="estimated_shooters" min="1" max="999" value="{{ old('estimated_shooters', $match->estimated_shooters) }}" placeholder="e.g. 30" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-stone-400">Feeds the cost estimator on the Finances tab.</p>
                </div>
            </div>

            @if($match->supportsClubEntry())
                <div class="sm:col-span-2">
                    <label for="club_entry_fee" class="block text-xs font-medium text-stone-600 mb-1">Club entry fee (ZAR)</label>
                    <input type="number" name="club_entry_fee" id="club_entry_fee" step="0.01" min="0" value="{{ old('club_entry_fee', $match->club_entry_fee) }}" placeholder="Blank = same as entry fee" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-stone-400">Shooters who are not SAPRF members pay this price with no non-member surcharge. Leave blank and they pay the same as members. SAPRF and the platform each take a small percentage of the fee when the shooter pays through PayFast — nothing when you collect the money yourself.</p>
                </div>
            @endif

            @if($canSeeFeeOverrides)
                <div class="sm:col-span-2 rounded-lg border border-stone-200 bg-stone-50 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-stone-800">Fee Overrides</p>
                            <p class="mt-0.5 text-xs text-stone-500">
                                Leave blank to inherit the global rate. Override to charge a different
                                SAPRF or platform fee for this match — e.g. R0 for matches not running through the platform.
                            </p>
                        </div>
                        <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-600">
                            {{ $canEditFeeOverrides ? 'Exco / Developer' : 'Read-only' }}
                        </span>
                    </div>

                    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {{-- Platform Fee override --}}
                        <div x-data="{
                                type: '{{ old('platform_fee_type', $match->platform_fee_type ?? '') }}',
                                value: '{{ old('platform_fee_value', $match->platform_fee_value ?? '') }}',
                            }">
                            <label class="block text-xs font-medium text-stone-600 mb-1">Platform fee</label>
                            <div class="flex gap-2">
                                <select name="platform_fee_type" x-model="type" @change="if (!type) value = ''"
                                        @disabled(!$canEditFeeOverrides)
                                        class="rounded-lg border border-stone-300 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-stone-100 disabled:text-stone-500">
                                    <option value="">Inherit ({{ $formatGlobal($globalPlatformType, $globalPlatformValue) }})</option>
                                    <option value="fixed">R fixed / shooter</option>
                                    <option value="percentage">% of match fee</option>
                                </select>
                                <div class="relative flex-1">
                                    <input type="number" name="platform_fee_value" step="0.01" min="0" x-model="value"
                                           :required="!!type"
                                           :disabled="!type || {{ $canEditFeeOverrides ? 'false' : 'true' }}"
                                           placeholder="—"
                                           class="block w-full rounded-lg border border-stone-300 py-2 pr-8 text-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-stone-100 disabled:text-stone-400">
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs font-semibold text-stone-400" x-text="type === 'percentage' ? '%' : (type === 'fixed' ? 'R' : '')"></span>
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
                            <label class="block text-xs font-medium text-stone-600 mb-1">SAPRF fee</label>
                            <div class="flex gap-2">
                                <select name="saprf_fee_type" x-model="type" @change="if (!type) value = ''"
                                        @disabled(!$canEditFeeOverrides)
                                        class="rounded-lg border border-stone-300 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-stone-100 disabled:text-stone-500">
                                    <option value="">Inherit ({{ $formatGlobal($globalSaprfType, $globalSaprfValue) }})</option>
                                    <option value="fixed">R fixed / shooter</option>
                                    <option value="percentage">% of match fee</option>
                                </select>
                                <div class="relative flex-1">
                                    <input type="number" name="saprf_fee_value" step="0.01" min="0" x-model="value"
                                           :required="!!type"
                                           :disabled="!type || {{ $canEditFeeOverrides ? 'false' : 'true' }}"
                                           placeholder="—"
                                           class="block w-full rounded-lg border border-stone-300 py-2 pr-8 text-sm focus:border-emerald-500 focus:ring-emerald-500 disabled:bg-stone-100 disabled:text-stone-400">
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs font-semibold text-stone-400" x-text="type === 'percentage' ? '%' : (type === 'fixed' ? 'R' : '')"></span>
                                </div>
                            </div>
                            @error('saprf_fee_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            @error('saprf_fee_value') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Capacity & status --}}
    <div class="rounded-xl border border-stone-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-stone-900">Capacity &amp; status</h3>
        <p class="mt-0.5 text-xs text-stone-500">Entry limit, waitlist, and whether the match is open for sign-ups.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="max_competitors" class="block text-xs font-medium text-stone-600 mb-1">Maximum competitors</label>
                <input type="number" name="max_competitors" id="max_competitors" min="1" max="999" value="{{ old('max_competitors', $match->max_competitors) }}" placeholder="No limit" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                <p class="mt-1 text-xs text-stone-400">Leave blank for unlimited entries.</p>
            </div>
            <div>
                <label for="status" class="block text-xs font-medium text-stone-600 mb-1">Status</label>
                <select name="status" id="status" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="draft" @selected(old('status', $match->status) === 'draft')>Draft (not visible publicly)</option>
                    <option value="open" @selected(old('status', $match->status) === 'open')>Open (registration available)</option>
                    <option value="closed" @selected(old('status', $match->status) === 'closed')>Closed (registration shut)</option>
                    <option value="completed" @selected(old('status', $match->status) === 'completed')>Completed</option>
                    <option value="cancelled" @selected(old('status', $match->status) === 'cancelled')>Cancelled</option>
                </select>
            </div>

            <div class="sm:col-span-2">
                <label class="flex items-start gap-2">
                    <input type="hidden" name="waitlist_enabled" value="0">
                    <input type="checkbox" name="waitlist_enabled" value="1" @checked(old('waitlist_enabled', $match->waitlist_enabled)) class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                    <span>
                        <span class="block text-sm font-medium text-stone-800">Enable waitlist when full</span>
                        <span class="mt-0.5 block text-xs text-stone-500">Once capacity is reached, additional shooters can join a waitlist.</span>
                    </span>
                </label>
            </div>

            @role('owner|admin|exco|developer')
                <div class="sm:col-span-2 rounded-lg border border-stone-200 bg-stone-50 p-3">
                    <label class="flex items-start gap-2">
                        <input type="hidden" name="everyone_counts" value="0">
                        <input type="checkbox" name="everyone_counts" value="1" @checked(old('everyone_counts', $match->everyone_counts)) class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span>
                            <span class="block text-sm font-medium text-stone-800">All shooters count regardless of membership</span>
                            <span class="mt-0.5 block text-xs text-stone-500">Use for imported historical matches (e.g. Day-1 provincial extracts) where the organiser ruled everyone counts. Every score on this match evaluates to Eligible on save/re-import/re-evaluation, instead of running the usual membership check.</span>
                        </span>
                    </label>
                </div>
            @endrole
        </div>
    </div>

    {{-- Provincial dual-count (nationals only) --}}
    <div class="rounded-xl border border-stone-200 bg-white p-5" x-data="{ dualCount: {{ old('also_counts_for_provincial', $match->also_counts_for_provincial) ? 'true' : 'false' }} }">
        <h3 class="text-sm font-semibold text-stone-900">Provincial dual-count</h3>
        <p class="mt-0.5 text-xs text-stone-500">Lets Day 1 of a national also feed the provincial pool.</p>

        <label class="mt-4 flex items-start gap-2">
            <input type="hidden" name="also_counts_for_provincial" value="0">
            <input type="checkbox" name="also_counts_for_provincial" value="1" x-model="dualCount" @checked(old('also_counts_for_provincial', $match->also_counts_for_provincial)) class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
            <span>
                <span class="block text-sm font-medium text-stone-800">Day 1 also counts as a Provincial score</span>
                <span class="mt-0.5 block text-xs text-stone-500">Recommended for all 2-day nationals. Under PR22 pooled scoring, each shooter's <strong>Day 1 total</strong> is used as a provincial-pool contribution (in addition to counting Day 1 + Day 2 for the national pool). MDs enter Day 1 and Day 2 separately when scoring.</span>
            </span>
        </label>

        <div x-show="dualCount" x-cloak class="mt-4 space-y-3 rounded-lg border border-stone-200 bg-stone-50 p-4">
            <div>
                <label for="provincial_stage_columns" class="block text-xs font-medium text-stone-600 mb-1">Provincial stage columns</label>
                <input type="text" name="provincial_stage_columns" id="provincial_stage_columns" value="{{ old('provincial_stage_columns', $match->provincial_stage_columns) }}"
                       placeholder="e.g. stage_1, stage_2, stage_3, stage_4, stage_5"
                       class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                <p class="mt-1 text-xs text-stone-400">Comma-separated CSV column names for the stages that count for provincial. These must match the column headers in the PractiScore/Impact export. The system will sum these values per shooter.</p>
            </div>

            @if($match->scores()->whereNotNull('raw_meta')->exists())
                @php
                    $sampleMeta = $match->scores()->whereNotNull('raw_meta')->first()?->raw_meta;
                    $stageKeys = $sampleMeta ? collect(array_keys($sampleMeta))->filter(fn($k) => preg_match('/stage|day/i', $k))->values() : collect();
                @endphp
                @if($stageKeys->isNotEmpty())
                    <div class="rounded-lg border border-stone-200 bg-white p-3">
                        <p class="text-xs font-medium text-stone-500">Detected stage columns from imported scores:</p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach($stageKeys as $key)
                                <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 font-mono text-xs text-emerald-700 ring-1 ring-inset ring-emerald-200">{{ $key }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- Live scores from Impact --}}
    <div class="rounded-xl border border-stone-200 bg-white p-5 space-y-4">
        <div>
            <h3 class="text-sm font-semibold text-stone-900">Live scores from Impact</h3>
            <p class="mt-0.5 text-xs text-stone-500 leading-relaxed">
                Paste the link to this match on Impact — the match page itself (/match/view?id=…) or the results page. On the day it is shot, SAPRF pulls the scoreboard every 5 minutes.
                A shooter is added only when their Impact name matches an entry exactly. Anyone else stays off the board until you link them with the normal Impact import.
                A score you have adjusted is left as you saved it. The pull stops when Impact marks the match finished. It does not close the match or email anyone.
            </p>
        </div>

        <div>
            <label for="impact_ref" class="block text-xs font-medium text-stone-600 mb-1">Impact match link or match number</label>
            <input type="text" name="impact_ref" id="impact_ref" value="{{ old('impact_ref', $match->impact_match_id) }}" placeholder="https://www.impactscoring.net/match/view?id=10558" class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            @error('impact_ref') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-start gap-2">
            <input type="hidden" name="impact_live_sync" value="0">
            <input type="checkbox" name="impact_live_sync" value="1" @checked(filter_var(old('impact_live_sync', $match->impact_live_sync), FILTER_VALIDATE_BOOLEAN)) class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
            <span>
                <span class="block text-sm font-medium text-stone-800">Pull live scores every 5 minutes on match day</span>
                <span class="mt-0.5 block text-xs text-stone-500">Starts on the start date and keeps going through the end date. Outside those days nothing is pulled.</span>
            </span>
        </label>

        @if($match->impact_live_synced_at || $match->impact_live_ended_at || $match->impact_live_error)
            <div class="space-y-1 rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600">
                @if($match->impact_live_synced_at)
                    <p>Last pull: {{ $match->impact_live_synced_at->timezone('Africa/Johannesburg')->format('D j M Y, H:i') }}</p>
                @endif
                @if($match->impact_live_ended_at)
                    <p>Impact marked this match finished at {{ $match->impact_live_ended_at->timezone('Africa/Johannesburg')->format('D j M Y, H:i') }}. Live pull has stopped. Save a different Impact link to start again.</p>
                @endif
                @if($match->impact_live_error)
                    <p class="text-red-700">Last pull failed: {{ $match->impact_live_error }}</p>
                @endif
            </div>
        @endif
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
        <flux:button type="submit" variant="primary">Save changes</flux:button>
        <flux:button href="{{ route('matches.overview', $match) }}" wire:navigate variant="ghost">Cancel</flux:button>
    </div>
</form>
