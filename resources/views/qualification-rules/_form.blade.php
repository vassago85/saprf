@php
    $rule = $qualificationRule ?? null;
    $mode = old('scoring_mode', $rule?->scoring_mode ?? 'best_of_n');
    $provWeight = old('provincial_pool_weight_pct', $rule?->provincial_pool_weight_pct ?? 30);
    $natWeight = old('national_pool_weight_pct', $rule?->national_pool_weight_pct ?? 40);
    $champsWeight = old('champs_pool_weight_pct', $rule?->champs_pool_weight_pct ?? 30);
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

<div class="space-y-6"
     x-data="{
        mode: '{{ $mode }}',
        prov: {{ (float) $provWeight }},
        nat: {{ (float) $natWeight }},
        champs: {{ (float) $champsWeight }},
        provBestOf: {{ (int) old('provincial_pool_best_of', $rule?->provincial_pool_best_of ?? 3) }},
        natBestOf: {{ (int) old('national_pool_best_of', $rule?->national_pool_best_of ?? 2) }},
        champsBestOf: {{ (int) old('champs_pool_best_of', $rule?->champs_pool_best_of ?? 1) }},
        get totalWeight() { return (parseFloat(this.prov) || 0) + (parseFloat(this.nat) || 0) + (parseFloat(this.champs) || 0); },
        get weightOk() { return Math.abs(this.totalWeight - 100) < 0.01; }
     }">

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
        <h2 class="text-sm font-semibold text-stone-700 mb-4">Scoring Model</h2>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="flex items-start gap-3 rounded-xl border p-4 cursor-pointer transition"
                   :class="mode === 'best_of_n' ? 'border-emerald-500 bg-emerald-50/50 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300'">
                <input type="radio" name="scoring_mode" value="best_of_n" x-model="mode" class="mt-0.5 text-emerald-600 focus:ring-emerald-500">
                <span class="text-sm">
                    <span class="block font-semibold text-stone-900">Best-of-N (Classic)</span>
                    <span class="block text-xs text-stone-500 mt-0.5">Sum a shooter's top N normalised scores across all national/final matches. Optional finals multiplier.</span>
                </span>
            </label>
            <label class="flex items-start gap-3 rounded-xl border p-4 cursor-pointer transition"
                   :class="mode === 'weighted_pools' ? 'border-emerald-500 bg-emerald-50/50 ring-1 ring-emerald-500' : 'border-stone-200 hover:border-stone-300'">
                <input type="radio" name="scoring_mode" value="weighted_pools" x-model="mode" class="mt-0.5 text-emerald-600 focus:ring-emerald-500">
                <span class="text-sm">
                    <span class="block font-semibold text-stone-900">Weighted Pools (PR22)</span>
                    <span class="block text-xs text-stone-500 mt-0.5">Three pools (Provincial / National / SA Champs) each contribute a % of the season total (out of 100).</span>
                </span>
            </label>
        </div>
    </div>

    <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold text-stone-700 mb-4">Selection Requirements</h2>
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <label for="min_out_of_province_matches" class="block text-sm font-medium text-stone-700 mb-1">Min Out-of-Province Matches <span class="text-red-500">*</span></label>
                <input type="number" name="min_out_of_province_matches" id="min_out_of_province_matches" value="{{ old('min_out_of_province_matches', $rule?->min_out_of_province_matches ?? 0) }}" min="0" max="20" required class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <p class="mt-1 text-xs text-stone-400">Number of out-of-province nationals required to qualify for finals selection.</p>
            </div>
            <div>
                <label for="total_qualifying_matches" class="block text-sm font-medium text-stone-700 mb-1">Expected Total Qualifying Matches</label>
                <input type="number" name="total_qualifying_matches" id="total_qualifying_matches" value="{{ old('total_qualifying_matches', $rule?->total_qualifying_matches) }}" min="1" max="30" placeholder="Optional" class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <p class="mt-1 text-xs text-stone-400">Used on member dashboards to show progress. Optional.</p>
            </div>
        </div>
    </div>

    {{-- Best-of-N config (classic mode) --}}
    <div x-show="mode === 'best_of_n'" x-cloak class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-semibold text-stone-700 mb-4">Best-Of Configuration</h2>
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <label for="best_of_count" class="block text-sm font-medium text-stone-700 mb-1">Best-Of Match Count</label>
                <input type="number" name="best_of_count" id="best_of_count" value="{{ old('best_of_count', $rule?->best_of_count) }}" min="1" max="20" placeholder="All matches" class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <p class="mt-1 text-xs text-stone-400">A shooter's top N normalised scores will be summed. Leave blank to count every match.</p>
            </div>
        </div>

        <div class="mt-6 border-t border-stone-100 pt-6" x-data="{ weighted: {{ old('weighted_final_enabled', $rule?->weighted_final_enabled) ? 'true' : 'false' }} }">
            <label class="flex items-start gap-2">
                <input type="hidden" name="weighted_final_enabled" value="0">
                <input type="checkbox" name="weighted_final_enabled" value="1" x-model="weighted" @checked(old('weighted_final_enabled', $rule?->weighted_final_enabled)) class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                <span class="text-sm text-stone-700">
                    Apply a weighting multiplier to <strong>Final / Champs</strong> matches
                    <span class="block text-xs font-normal text-stone-400 mt-0.5">Scores at matches with series level "Final" are multiplied by the value below.</span>
                </span>
            </label>
            <div x-show="weighted" x-cloak class="mt-4">
                <label for="weighted_final_multiplier" class="block text-sm font-medium text-stone-700 mb-1">Weighting Multiplier</label>
                <input type="number" name="weighted_final_multiplier" id="weighted_final_multiplier" step="0.05" min="1" max="5" value="{{ old('weighted_final_multiplier', $rule?->weighted_final_multiplier ?? '1.50') }}" class="block w-full sm:max-w-xs rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <p class="mt-1 text-xs text-stone-400">e.g. 1.50 means a Final score counts 1.5x its normal value. Range: 1.00 to 5.00.</p>
            </div>
        </div>
    </div>

    {{-- Weighted Pools config (PR22 mode) --}}
    <div x-show="mode === 'weighted_pools'" x-cloak class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        <div class="flex items-start justify-between mb-4">
            <div>
                <h2 class="text-sm font-semibold text-stone-700">Weighted Pool Configuration</h2>
                <p class="text-xs text-stone-500 mt-0.5">Configure how each pool contributes to the season total. Pool weights must add up to 100%.</p>
            </div>
            <div class="text-right shrink-0">
                <div class="text-xs font-medium text-stone-500">Total weight</div>
                <div class="mt-0.5 text-lg font-bold" :class="weightOk ? 'text-emerald-600' : 'text-red-600'">
                    <span x-text="totalWeight.toFixed(0)"></span>%
                </div>
                <div x-show="!weightOk" x-cloak class="text-[10px] font-medium text-red-600">Must equal 100%</div>
            </div>
        </div>

        <div class="space-y-4">
            {{-- Provincial pool --}}
            <div class="rounded-lg border border-stone-200 bg-stone-50/50 p-4">
                <div class="flex items-center gap-2 mb-3">
                    <div class="h-2 w-2 rounded-full bg-blue-500"></div>
                    <h3 class="text-sm font-semibold text-stone-800">Provincial Pool</h3>
                    <span class="text-xs text-stone-500">— Any match with series level "Provincial"</span>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="provincial_pool_best_of" class="block text-xs font-medium text-stone-700 mb-1">Best of… (N scores)</label>
                        <input type="number" name="provincial_pool_best_of" id="provincial_pool_best_of" x-model.number="provBestOf" min="1" max="20" class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label for="provincial_pool_weight_pct" class="block text-xs font-medium text-stone-700 mb-1">Weight (%)</label>
                        <div class="relative">
                            <input type="number" name="provincial_pool_weight_pct" id="provincial_pool_weight_pct" x-model="prov" step="0.01" min="0" max="100" class="block w-full rounded-lg border border-stone-300 pl-3 pr-8 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <span class="absolute inset-y-0 right-3 flex items-center text-xs text-stone-400">%</span>
                        </div>
                    </div>
                </div>
                <p class="mt-2 text-xs text-stone-500">
                    Take a shooter's best <strong x-text="provBestOf"></strong> provincial scores, average them, and multiply by <strong x-text="prov + '%'"></strong>.
                    Fewer than <strong x-text="provBestOf"></strong> scores means the missing ones count as 0.
                </p>
            </div>

            {{-- National pool --}}
            <div class="rounded-lg border border-stone-200 bg-stone-50/50 p-4">
                <div class="flex items-center gap-2 mb-3">
                    <div class="h-2 w-2 rounded-full bg-emerald-500"></div>
                    <h3 class="text-sm font-semibold text-stone-800">National Pool</h3>
                    <span class="text-xs text-stone-500">— Any match with series level "National" (the 2-day nationals)</span>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="national_pool_best_of" class="block text-xs font-medium text-stone-700 mb-1">Best of… (N scores)</label>
                        <input type="number" name="national_pool_best_of" id="national_pool_best_of" x-model.number="natBestOf" min="1" max="20" class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label for="national_pool_weight_pct" class="block text-xs font-medium text-stone-700 mb-1">Weight (%)</label>
                        <div class="relative">
                            <input type="number" name="national_pool_weight_pct" id="national_pool_weight_pct" x-model="nat" step="0.01" min="0" max="100" class="block w-full rounded-lg border border-stone-300 pl-3 pr-8 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <span class="absolute inset-y-0 right-3 flex items-center text-xs text-stone-400">%</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Champs pool --}}
            <div class="rounded-lg border border-stone-200 bg-stone-50/50 p-4">
                <div class="flex items-center gap-2 mb-3">
                    <div class="h-2 w-2 rounded-full bg-amber-500"></div>
                    <h3 class="text-sm font-semibold text-stone-800">SA Champs Pool</h3>
                    <span class="text-xs text-stone-500">— Any match with series level "Final / Champs"</span>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="champs_pool_best_of" class="block text-xs font-medium text-stone-700 mb-1">Best of… (N scores)</label>
                        <input type="number" name="champs_pool_best_of" id="champs_pool_best_of" x-model.number="champsBestOf" min="1" max="20" class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                        <p class="mt-1 text-[10px] text-stone-400">Usually 1 — most series only have one champs match per season.</p>
                    </div>
                    <div>
                        <label for="champs_pool_weight_pct" class="block text-xs font-medium text-stone-700 mb-1">Weight (%)</label>
                        <div class="relative">
                            <input type="number" name="champs_pool_weight_pct" id="champs_pool_weight_pct" x-model="champs" step="0.01" min="0" max="100" class="block w-full rounded-lg border border-stone-300 pl-3 pr-8 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                            <span class="absolute inset-y-0 right-3 flex items-center text-xs text-stone-400">%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800">
            <strong>Example:</strong> A shooter with best 3 provincial avg 82%, best 2 national avg 88%, and champs 76% under 30/40/30 gets:
            <span class="block mt-1 font-mono">(82 × 0.30) + (88 × 0.40) + (76 × 0.30) = 24.6 + 35.2 + 22.8 = <strong>82.6 / 100</strong></span>
        </div>
    </div>
</div>

<div class="mt-6 flex items-center gap-3">
    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
        {{ isset($qualificationRule) ? 'Update Rule' : 'Create Rule' }}
    </button>
    <a href="{{ route('qualification-rules.index') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">Cancel</a>
</div>
