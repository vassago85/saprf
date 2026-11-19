{{--
    Section 1 of 7 — Basics.
    Shared by /matches/create and /matches/{match}/settings. Reads the
    current value either from `$match` (settings) or from the posted
    `old()` session (create). `$lockedByPaidEntries` is only true on
    settings when the match already has paid entries; the create page
    always passes false.
--}}
@php
    /** @var \App\Models\MatchEvent|null $match */
    $match = $match ?? null;
    $lockedByPaidEntries = $lockedByPaidEntries ?? false;
    $paidCount = $counts['paid'] ?? 0;
    $divisions = \App\Models\Division::active()->ordered()->get();
    $selectedDivisionIds = $match
        ? old('divisions', $match->divisions->pluck('id')->all())
        : old('divisions', []);
    $clubs = \App\Models\Club::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    $registrationMethod = old('registration_method', $match?->registration_method ?? 'platform');
    $externalUrl = old('external_registration_url', $match?->external_registration_url);
@endphp
<section id="basics" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-5"
         x-data="{
            matchType: '{{ old('match_type', $match?->match_type) }}',
            seriesLevel: '{{ old('series_level', $match?->series_level) }}',
            registrationMethod: '{{ $registrationMethod }}',
         }">
    <header>
        <h3 class="text-sm font-semibold text-stone-900">Basics</h3>
        <p class="mt-0.5 text-xs text-stone-500">Headline details that identify the match on the public listing.</p>
    </header>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label for="name" class="block text-xs font-medium text-stone-600 mb-1">Match name <span class="text-red-500">*</span></label>
            <input type="text" name="name" id="name" value="{{ old('name', $match?->name) }}" required
                   placeholder="e.g. Centrefire WC 2-Day National"
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            @unless($match)
                <p class="mt-1 text-xs text-stone-400">Spelling is normalised on save: use <code>Centrefire</code>, <code>1-Day</code> / <code>2-Day</code>, <code>Day 1</code>.</p>
            @endunless
            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="match_type" class="block text-xs font-medium text-stone-600 mb-1">Match type <span class="text-red-500">*</span></label>
            <select name="match_type" id="match_type" required x-model="matchType"
                    class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Select type…</option>
                <option value="PRS" @selected(old('match_type', $match?->match_type) === 'PRS')>PRS</option>
                <option value="PR22" @selected(old('match_type', $match?->match_type) === 'PR22')>PR22</option>
            </select>
            @error('match_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="series_level" class="block text-xs font-medium text-stone-600 mb-1">Series level <span class="text-red-500">*</span></label>
            <select name="series_level" id="series_level" required x-model="seriesLevel"
                    class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Select level…</option>
                <option value="national" @selected(old('series_level', $match?->series_level) === 'national')>National</option>
                <option value="provincial" @selected(old('series_level', $match?->series_level) === 'provincial')>Provincial</option>
                <option value="club_only" @selected(old('series_level', $match?->series_level) === 'club_only')>Club only</option>
                <option value="club_provincial" @selected(old('series_level', $match?->series_level) === 'club_provincial')>Club / Provincial</option>
                <option value="final" @selected(old('series_level', $match?->series_level) === 'final')>Final / Champs</option>
                <option value="international" @selected(old('series_level', $match?->series_level) === 'international')>International</option>
            </select>
            <p class="mt-1 text-xs text-stone-400">Finals attract the configured weighting multiplier in season standings.</p>
            @error('series_level') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

            {{-- Club-hosted matches: pick the host club and how shooters
                 register. Club only limits entry to that club's members;
                 Club / Provincial opens to full members (plus club members
                 when Allow club members is on under Divisions) and feeds
                 the provincial log. External registration still creates the
                 SAPRF entry — PayFast is skipped and the MD marks it paid
                 once the club collects the fee. --}}
            <div x-show="seriesLevel === 'club_only' || seriesLevel === 'club_provincial'" x-cloak class="mt-3 space-y-3">
                <div>
                    <label for="club_id" class="block text-xs font-medium text-stone-600 mb-1">Host club <span class="text-red-500">*</span></label>
                    <select name="club_id" id="club_id"
                            class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">Select club…</option>
                        @foreach($clubs as $c)
                            <option value="{{ $c->id }}" @selected((int) old('club_id', $match?->club_id) === (int) $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-stone-400">
                        <span x-show="seriesLevel === 'club_only'" x-cloak>Only members of this club will be able to enter.</span>
                        <span x-show="seriesLevel === 'club_provincial'" x-cloak>The club hosts the match; entry opens to the whole province.</span>
                    </p>
                    @error('club_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-stone-600 mb-1">Registration</label>
                    <div class="space-y-2">
                        <label class="flex items-start gap-2 cursor-pointer rounded-lg border border-stone-200 bg-white px-3 py-2 has-[:checked]:border-emerald-500 has-[:checked]:ring-1 has-[:checked]:ring-emerald-500">
                            <input type="radio" name="registration_method" value="platform" x-model="registrationMethod"
                                   class="mt-0.5 text-emerald-600 focus:ring-emerald-500" />
                            <span class="text-xs text-stone-700">
                                <span class="block text-xs font-semibold text-stone-900">Pay on SAPRF (PayFast)</span>
                                <span class="mt-0.5 block text-[11px] text-stone-500">The shooter enters and pays here. Standard SAPRF and platform fees apply.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2 cursor-pointer rounded-lg border border-stone-200 bg-white px-3 py-2 has-[:checked]:border-emerald-500 has-[:checked]:ring-1 has-[:checked]:ring-emerald-500">
                            <input type="radio" name="registration_method" value="external" x-model="registrationMethod"
                                   class="mt-0.5 text-emerald-600 focus:ring-emerald-500" />
                            <span class="text-xs text-stone-700">
                                <span class="block text-xs font-semibold text-stone-900">External link</span>
                                <span class="mt-0.5 block text-[11px] text-stone-500">The shooter creates an entry on SAPRF, then is sent to the club's own registration page or bank details. The entry stays unpaid until you mark it paid.</span>
                            </span>
                        </label>
                    </div>
                    @error('registration_method') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div x-show="registrationMethod === 'external'" x-cloak>
                    <label for="external_registration_url" class="block text-xs font-medium text-stone-600 mb-1">Club registration URL <span class="text-red-500">*</span></label>
                    <input type="url" name="external_registration_url" id="external_registration_url"
                           value="{{ $externalUrl }}"
                           placeholder="https://yourclub.co.za/register/..."
                           class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                    <p class="mt-1 text-xs text-stone-400">Full URL. Can point at your own entry form, a Google Form, bank-details page — anything that explains what to do next.</p>
                    @error('external_registration_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- SA Championships flag. Only visible when the level is "Final /
                 Champs" — a club or provincial championship is a final but is
                 NOT the SA Championships, and federation colours are only
                 awarded off matches the admin has explicitly flagged here. --}}
            <div x-show="seriesLevel === 'final'" x-cloak class="mt-3">
                <input type="hidden" name="is_sa_championships" value="0" />
                <label class="flex items-start gap-2 cursor-pointer rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-2">
                    <input type="checkbox" name="is_sa_championships" value="1"
                           @checked(old('is_sa_championships', (bool) ($match?->is_sa_championships ?? false)))
                           class="mt-0.5 rounded border-stone-300 text-amber-600 focus:ring-amber-500" />
                    <span class="text-xs text-stone-700">
                        <span class="block text-xs font-semibold text-stone-900">This is the SA Championships</span>
                        <span class="mt-0.5 block text-[11px] text-stone-500">
                            Tick this ONLY for the actual SAPRF SA Championships. Federation colours
                            only count a shooter as qualified once they've shot a match flagged here —
                            a club or provincial "championship" or "final" must stay unticked.
                        </span>
                    </span>
                </label>
                @error('is_sa_championships') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-stone-600 mb-1">Divisions on offer</label>
            <p class="text-xs text-stone-400 mb-2">Shooters can only enter the divisions you tick.</p>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                @foreach($divisions as $division)
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-stone-200 px-3 py-2 text-sm hover:bg-stone-50">
                        <input type="checkbox" name="divisions[]" value="{{ $division->id }}"
                               @checked(in_array($division->id, $selectedDivisionIds))
                               class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span>{{ $division->name }}</span>
                    </label>
                @endforeach
            </div>
            @error('divisions') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

            @php
                $seriesLevel = old('series_level', $match?->series_level);
                // Toggle shows on the two members-only levels: a plain
                // provincial and a Club / Provincial (which is a provincial
                // for entry and standings purposes). On Club only the host
                // club IS the gate — there's nothing for this toggle to say.
                $showClubMemberToggle = $match && in_array($seriesLevel, ['provincial', 'club_provincial'], true);
                $clubMembersAllowed = filter_var(
                    old('club_entry_enabled', (bool) ($match?->club_entry_enabled ?? false)),
                    FILTER_VALIDATE_BOOLEAN,
                );
                $clubFee = old('club_entry_fee', $match?->club_entry_fee);
            @endphp
            @if($showClubMemberToggle)
                {{-- Off by default. Full members can always enter; club members
                     cannot until the match director opts in. The fee below is
                     what those club members pay once they are allowed. --}}
                <div class="mt-4 rounded-lg border border-stone-200 bg-stone-50/60 p-4"
                     x-data="{ enabled: {{ $clubMembersAllowed ? 'true' : 'false' }} }">
                    <input type="hidden" name="club_entry_enabled" value="0" />
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="club_entry_enabled" value="1" x-model="enabled"
                               @checked($clubMembersAllowed)
                               class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500" />
                        <span class="text-sm text-stone-700">
                            <span class="block text-sm font-medium text-stone-900">Allow club members</span>
                            <span class="mt-0.5 block text-xs text-stone-500">
                                Off unless you turn this on. Full SAPRF members can always enter. Club members, associates, and anyone without a full membership cannot register for this match until you allow them. When allowed, they pay the club price below. Their scores stay on the match and do not count toward the provincial log.
                            </span>
                        </span>
                    </label>

                    <div x-show="enabled" x-cloak class="mt-4">
                        <label for="club_entry_fee" class="block text-xs font-medium text-stone-600 mb-1">Club entry fee (ZAR)</label>
                        <input type="number" name="club_entry_fee" id="club_entry_fee" step="0.01" min="0" value="{{ $clubFee }}"
                               placeholder="Blank = same as entry fee"
                               class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                        <p class="mt-1 text-xs text-stone-400">Leave blank and club members pay the same as full members.</p>
                        @error('club_entry_fee') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>
