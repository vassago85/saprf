@php
    $rule = $qualificationRule ?? null;
@endphp

@if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
        <ul class="list-inside list-disc space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="space-y-6">
    <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold text-stone-700 mb-4">Series and Season</h2>
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <label for="series" class="block text-sm font-medium text-stone-700 mb-1">Series <span class="text-red-500">*</span></label>
                <select name="series" id="series" required class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    <option value="">Select series…</option>
                    <option value="PRS" @selected(old('series', $rule?->series) === 'PRS')>PRS (Centrefire)</option>
                    <option value="PR22" @selected(old('series', $rule?->series) === 'PR22')>PR22 (Rimfire)</option>
                </select>
            </div>
            <div>
                <label for="season" class="block text-sm font-medium text-stone-700 mb-1">Season <span class="text-red-500">*</span></label>
                <input type="number" name="season" id="season" value="{{ old('season', $rule?->season ?? now()->year) }}" min="2020" max="2099" required class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <p class="mt-1 text-xs text-stone-400">The competition year these rules apply to.</p>
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold text-stone-700 mb-4">Selection &amp; Best-Of Rules</h2>
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <label for="min_out_of_province_matches" class="block text-sm font-medium text-stone-700 mb-1">Min Out-of-Province Matches <span class="text-red-500">*</span></label>
                <input type="number" name="min_out_of_province_matches" id="min_out_of_province_matches" value="{{ old('min_out_of_province_matches', $rule?->min_out_of_province_matches ?? 0) }}" min="0" max="20" required class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <p class="mt-1 text-xs text-stone-400">Number of out-of-province nationals required to qualify for finals selection.</p>
            </div>
            <div>
                <label for="best_of_count" class="block text-sm font-medium text-stone-700 mb-1">Best-Of Match Count</label>
                <input type="number" name="best_of_count" id="best_of_count" value="{{ old('best_of_count', $rule?->best_of_count) }}" min="1" max="20" placeholder="All matches" class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <p class="mt-1 text-xs text-stone-400">A shooter's top N normalised scores will be summed for season totals. Leave blank to count every match.</p>
            </div>
            <div class="sm:col-span-2">
                <label for="total_qualifying_matches" class="block text-sm font-medium text-stone-700 mb-1">Expected Total Qualifying Matches</label>
                <input type="number" name="total_qualifying_matches" id="total_qualifying_matches" value="{{ old('total_qualifying_matches', $rule?->total_qualifying_matches) }}" min="1" max="30" placeholder="Optional" class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <p class="mt-1 text-xs text-stone-400">Used on member dashboards to show progress (e.g. "3 of 6 qualifying matches shot"). Optional.</p>
            </div>
        </div>
    </div>

    <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm" x-data="{ weighted: {{ old('weighted_final_enabled', $rule?->weighted_final_enabled) ? 'true' : 'false' }} }">
        <h2 class="text-sm font-semibold text-stone-700 mb-4">Finals Weighting</h2>
        <label class="flex items-start gap-2">
            <input type="hidden" name="weighted_final_enabled" value="0">
            <input type="checkbox" name="weighted_final_enabled" value="1" x-model="weighted" @checked(old('weighted_final_enabled', $rule?->weighted_final_enabled)) class="mt-0.5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
            <span class="text-sm text-stone-700">
                Apply a weighting multiplier to <strong>Final / Champs</strong> matches in season standings
                <span class="block text-xs font-normal text-stone-400 mt-0.5">When enabled, scores at matches with series level "Final" are multiplied by the value below.</span>
            </span>
        </label>
        <div x-show="weighted" x-cloak class="mt-4">
            <label for="weighted_final_multiplier" class="block text-sm font-medium text-stone-700 mb-1">Weighting Multiplier</label>
            <input type="number" name="weighted_final_multiplier" id="weighted_final_multiplier" step="0.05" min="1" max="5" value="{{ old('weighted_final_multiplier', $rule?->weighted_final_multiplier ?? '1.50') }}" class="block w-full sm:max-w-xs rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
            <p class="mt-1 text-xs text-stone-400">e.g. 1.50 means a Final score counts 1.5x its normal value in season totals. Range: 1.00 to 5.00.</p>
        </div>
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
        {{ isset($qualificationRule) ? 'Update Rule' : 'Create Rule' }}
    </button>
    <a href="{{ route('qualification-rules.index') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">Cancel</a>
</div>
