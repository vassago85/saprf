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
@endphp
<section id="basics" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-5"
         x-data="{ matchType: '{{ old('match_type', $match?->match_type) }}' }">
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
            <select name="series_level" id="series_level" required
                    class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Select level…</option>
                <option value="national" @selected(old('series_level', $match?->series_level) === 'national')>National</option>
                <option value="provincial" @selected(old('series_level', $match?->series_level) === 'provincial')>Provincial</option>
                <option value="club" @selected(old('series_level', $match?->series_level) === 'club')>Club</option>
                <option value="final" @selected(old('series_level', $match?->series_level) === 'final')>Final / Champs</option>
                <option value="international" @selected(old('series_level', $match?->series_level) === 'international')>International</option>
            </select>
            <p class="mt-1 text-xs text-stone-400">Finals attract the configured weighting multiplier in season standings.</p>
            @error('series_level') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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
        </div>
    </div>
</section>
