<x-layouts.app :title="'Upload Scores'">
    <div class="max-w-5xl space-y-6"
         x-data="scoreImportWizard(@js([
             'matchMeta' => $matchMeta,
             'preselectedMatchId' => old('match_id', $preselectedMatchId),
             'previewUrl' => route('score-imports.impact.preview'),
             'importUrl' => route('score-imports.impact.store'),
             'csvPreviewUrl' => route('score-imports.csv.preview'),
             'initialMode' => $initialMode ?? 'impact',
             'csrf' => csrf_token(),
         ]))"
         x-cloak>

        <div>
            @if ($lockedMatch ?? null)
                <a href="{{ route('matches.show', $lockedMatch) }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; {{ $lockedMatch->name }}</a>
                <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Import from Impact</h1>
                <p class="mt-1 text-sm text-stone-500">Live pull for this match. Confirm fuzzy names, or choose <span class="font-medium text-stone-700">Assign later</span> when you need to check first.</p>
            @else
                <a href="{{ route('score-imports.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; All Imports</a>
                <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Upload Scores</h1>
                <p class="mt-1 text-sm text-stone-500">Pull live from Impact Scoring, or upload a CSV. Standings recalculate automatically.</p>
            @endif
        </div>

        @if (session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        {{-- Mode tabs (CSV hidden when launched from a match Impact button) --}}
        @unless ($lockedMatch ?? null)
        <div class="flex gap-1 rounded-2xl border border-stone-200 bg-stone-100/80 p-1">
            <button type="button" @click="mode = 'impact'"
                    :class="mode === 'impact' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-700'"
                    class="flex-1 rounded-xl px-4 py-2.5 text-sm font-semibold transition">
                Impact Scoring (live)
            </button>
            <button type="button" @click="mode = 'csv'"
                    :class="mode === 'csv' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-700'"
                    class="flex-1 rounded-xl px-4 py-2.5 text-sm font-semibold transition">
                CSV upload
            </button>
        </div>
        @endunless

        {{-- ═══════════════ IMPACT LIVE ═══════════════ --}}
        <div x-show="mode === 'impact'" class="space-y-5">
            <div class="rounded-2xl border border-emerald-200/80 bg-gradient-to-br from-emerald-50/80 via-white to-sky-50/40 p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-700 text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-heading text-lg font-bold text-stone-900">How to pull from Impact</h2>
                        <ol class="mt-2 space-y-1.5 text-sm text-stone-600 list-decimal list-inside">
                            <li>Open the match itself on <a href="https://www.impactscoring.net/matches" target="_blank" class="font-medium text-emerald-700 hover:underline">impactscoring.net</a> (the match page or Results).</li>
                            <li>Copy the URL (or just the match id, e.g. <code class="rounded bg-white/80 px-1 text-xs">11086</code>).</li>
                            <li>Select this SAPRF match, paste the URL, preview, confirm any fuzzy name matches, then import.</li>
                            <li>Not sure who someone is? Choose <strong>Assign later</strong> — the score imports as a stub (doesn’t count) until you link a member.</li>
                            <li>Registration divisions win (Ladies / Junior / Senior / Factory). Impact “Production” maps to Factory.</li>
                            <li>Wrong Impact total? Edit the hits on the preview table before import — it’s stored as “adjusted from Impact”.</li>
                            <li>After import you’ll get a full audit: unpaid, walk-ins, assign-later, skipped, non-members.</li>
                        </ol>
                    </div>
                </div>
            </div>

            <form method="POST" :action="importUrl" @submit="onImpactSubmit" class="space-y-5">
                <input type="hidden" name="_token" :value="csrf">
                <template x-for="(link, sid) in links" :key="sid">
                    <input type="hidden" :name="'links['+sid+']'" :value="link">
                </template>
                <template x-for="sid in day1StageIds" :key="'d1-'+sid">
                    <input type="hidden" name="day1_stage_ids[]" :value="sid">
                </template>
                <template x-for="item in overrideFields()" :key="item.key">
                    <input type="hidden" :name="item.name" :value="item.value">
                </template>

                <div class="rounded-2xl border border-stone-200 bg-white shadow-sm p-6 space-y-5">
                    <div>
                        <label class="block text-sm font-medium text-stone-700 mb-1">SAPRF match <span class="text-red-500">*</span></label>
                        @if ($lockedMatch ?? null)
                            <input type="hidden" name="match_id" value="{{ $lockedMatch->id }}">
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3">
                                <p class="font-semibold text-stone-900">{{ $lockedMatch->name }}</p>
                                <p class="text-xs text-stone-500 mt-0.5">{{ $lockedMatch->match_date?->format('d M Y') }}{{ $lockedMatch->isMultiDay() ? ' · 2-day' : '' }}</p>
                            </div>
                        @else
                            <select name="match_id" required x-model="matchId" @change="resetPreview()"
                                    class="block w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Select match…</option>
                                @foreach ($matches as $match)
                                    <option value="{{ $match->id }}">
                                        {{ $match->name }} ({{ $match->match_date->format('d M Y') }}{{ $match->isMultiDay() ? ' – 2-day' : '' }})
                                    </option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-stone-700 mb-1">Impact match URL or id <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <input type="text" name="impact_ref" x-model="impactRef" required
                                   placeholder="https://www.impactscoring.net/match/view?id=10558"
                                   class="block w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                            <button type="button" @click="runPreview()" :disabled="previewLoading || !matchId || !impactRef"
                                    class="shrink-0 inline-flex items-center gap-2 rounded-xl bg-stone-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-stone-800 disabled:opacity-40 transition">
                                <svg x-show="previewLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                <span x-text="previewLoading ? 'Loading…' : 'Preview'"></span>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-stone-400">Paste the match page (/match/view?id=…), the results link, or the numeric id.</p>
                    </div>

                    {{-- Day 1 / Overall for 2-day nationals --}}
                    <div x-show="isTwoDayNational" class="rounded-xl border border-blue-200 bg-blue-50/50 p-4 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-stone-900 mb-2">What are you importing? <span class="text-red-500">*</span></label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition"
                                       :class="scoreScope === 'day1' ? 'border-blue-500 bg-white ring-1 ring-blue-500' : 'border-stone-200 bg-white hover:border-stone-300'">
                                    <input type="radio" name="score_scope" value="day1" x-model="scoreScope" @change="onScopeChange()" class="mt-0.5 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm">
                                        <span class="block font-semibold text-stone-900">Day 1 → provincial</span>
                                        <span class="block text-xs text-stone-500 mt-0.5">Day 1 is the sum of each Day 1 stage. Scores land on the provincial sibling.</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition"
                                       :class="scoreScope === 'overall' ? 'border-blue-500 bg-white ring-1 ring-blue-500' : 'border-stone-200 bg-white hover:border-stone-300'">
                                    <input type="radio" name="score_scope" value="overall" x-model="scoreScope" @change="onScopeChange()" class="mt-0.5 text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm">
                                        <span class="block font-semibold text-stone-900">Overall (full match)</span>
                                        <span class="block text-xs text-stone-500 mt-0.5">Full Impact total on this national. Does not feed provincial standings.</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div x-show="scoreScope === 'day1' && preview?.impact_stages?.length" class="space-y-3">
                            <div>
                                <p class="text-sm font-medium text-stone-900">Day 1 stages</p>
                                <p class="text-xs text-stone-500 mt-0.5" x-text="stageDaySummary()"></p>
                            </div>
                            <template x-for="group in stageDayGroups()" :key="'day-group-'+group.day">
                                <div class="space-y-1.5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-400" x-text="group.label"></p>
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="stage in group.stages" :key="stage.id">
                                            <label class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium cursor-pointer transition"
                                                   :class="day1StageIds.includes(Number(stage.id)) ? 'border-blue-500 bg-blue-600 text-white' : 'border-stone-200 bg-white text-stone-700 hover:border-stone-300'">
                                                <input type="checkbox" class="sr-only" :value="stage.id"
                                                       :checked="day1StageIds.includes(Number(stage.id))"
                                                       @change="toggleDay1Stage(stage.id)">
                                                <span x-text="stage.name"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-4">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="hidden" name="replace_existing" value="0">
                            <input type="checkbox" name="replace_existing" value="1" x-model="replaceExisting"
                                   class="mt-1 rounded border-amber-400 text-amber-600 focus:ring-amber-500">
                            <div class="text-sm">
                                <span class="font-semibold text-stone-900">Replace existing scores on the target match</span>
                                <p class="mt-0.5 text-xs text-stone-600">Checked by default for re-pulls. Clears scores (and linked shooter logs) on the match this import writes to.</p>
                            </div>
                        </label>
                    </div>
                </div>

                {{-- Preview panel --}}
                <div x-show="previewError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" x-text="previewError"></div>

                <div x-show="preview" class="space-y-4">
                    <div class="rounded-2xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 bg-stone-50/80 px-5 py-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-stone-400">Impact match</p>
                                <p class="font-heading text-lg font-bold text-stone-900" x-text="preview?.impact_match_name"></p>
                                <p class="text-xs text-stone-500 mt-0.5">
                                    #<span x-text="preview?.impact_match_id"></span>
                                    · <span x-text="preview?.impact_match_ended ? 'Final results' : 'Live / in progress'"></span>
                                    · <span x-text="preview?.summary?.total"></span> shooters
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <span class="rounded-full bg-emerald-100 text-emerald-800 px-2.5 py-1 font-medium" x-text="(preview?.summary?.exact||0)+' matched'"></span>
                                <span class="rounded-full bg-amber-100 text-amber-900 px-2.5 py-1 font-medium" x-text="(preview?.summary?.fuzzy||0)+' need confirm'"></span>
                                <span class="rounded-full bg-stone-200 text-stone-700 px-2.5 py-1 font-medium" x-text="(preview?.summary?.unmatched||0)+' unmatched'"></span>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-stone-500">
                                    <tr>
                                        <th class="px-4 py-2.5 font-semibold">#</th>
                                        <th class="px-4 py-2.5 font-semibold">Impact shooter</th>
                                        <th class="px-4 py-2.5 font-semibold">Division</th>
                                        <th class="px-4 py-2.5 font-semibold text-right">Hits <span class="font-normal normal-case text-stone-400">(editable)</span></th>
                                        <th class="px-4 py-2.5 font-semibold text-right" x-show="scoreScope === 'day1'">Day 1</th>
                                        <th class="px-4 py-2.5 font-semibold">Link</th>
                                        <th class="px-4 py-2.5 font-semibold">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-stone-100">
                                    <template x-for="row in (preview?.shooters || [])" :key="row.impact_shooter_id">
                                        <tr class="align-top hover:bg-stone-50/60">
                                            <td class="px-4 py-3 text-stone-400 tabular-nums" x-text="row.impact_rank"></td>
                                            <td class="px-4 py-3">
                                                <button type="button" class="text-left font-medium text-stone-900 hover:text-emerald-800" @click="toggleStages(row)">
                                                    <span x-text="row.impact_name"></span>
                                                    <span class="ml-1 text-xs text-stone-400" x-show="row.stages?.length">▾</span>
                                                </button>
                                                <div x-show="expanded[row.impact_shooter_id] && row.stages?.length" class="mt-2 rounded-lg border border-stone-100 bg-stone-50 p-2 space-y-1">
                                                    <template x-for="st in row.stages" :key="st.id">
                                                        <div class="flex justify-between gap-3 text-xs text-stone-600">
                                                            <span x-text="(st.day ? 'D'+st.day+' · ' : '') + st.name"></span>
                                                            <span class="tabular-nums whitespace-nowrap">
                                                                <span class="font-medium text-stone-900" x-text="st.hits"></span> hit
                                                                · <span x-text="st.misses"></span> miss
                                                                · <span x-text="st.shots+'/'+st.max_shots"></span>
                                                                <span x-show="st.completed" class="text-emerald-600"> ✓</span>
                                                            </span>
                                                        </div>
                                                    </template>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center rounded-md bg-stone-100 px-2 py-0.5 text-xs font-medium capitalize text-stone-800" x-text="row.division_slug"></span>
                                                <span class="block text-[10px] text-stone-400 mt-0.5" x-text="row.division_source === 'registration' ? 'from entry' : 'from Impact'"></span>
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                <input type="number" min="0" max="9999" step="1"
                                                       class="w-20 rounded-lg border text-right text-sm tabular-nums py-1 px-2 focus:ring-emerald-500 focus:border-emerald-500"
                                                       :class="isAdjusted(row) ? 'border-amber-400 bg-amber-50 font-semibold text-amber-900' : 'border-stone-200 text-stone-900'"
                                                       :value="overrideImpacts(row)"
                                                       @input="setOverride(row.impact_shooter_id, 'impacts', $event.target.value, row.impacts)">
                                                <p class="text-[10px] text-amber-700 mt-0.5" x-show="Number(overrideImpacts(row)) !== Number(row.impacts)">
                                                    Impact: <span x-text="row.impacts"></span> · adjusted
                                                </p>
                                            </td>
                                            <td class="px-4 py-3 text-right" x-show="scoreScope === 'day1'">
                                                <input type="number" min="0" max="9999" step="1"
                                                       class="w-20 rounded-lg border text-right text-sm tabular-nums py-1 px-2 focus:ring-blue-500 focus:border-blue-500"
                                                       :class="Number(overrideDay1(row)) !== Number(row.day1_impacts) ? 'border-blue-400 bg-blue-50 font-semibold text-blue-900' : 'border-stone-200 text-blue-800'"
                                                       :value="overrideDay1(row)"
                                                       @input="setOverride(row.impact_shooter_id, 'day1_impacts', $event.target.value, row.day1_impacts)">
                                                <p class="text-[10px] text-blue-700 mt-0.5" x-show="Number(overrideDay1(row)) !== Number(row.day1_impacts)">
                                                    Impact Day 1: <span x-text="row.day1_impacts"></span>
                                                </p>
                                            </td>
                                            <td class="px-4 py-3 min-w-[12rem]">
                                                <template x-if="row.match_status === 'exact'">
                                                    <span class="text-xs text-emerald-700 font-medium" x-text="'✓ '+row.registration_name"></span>
                                                </template>
                                                <template x-if="row.match_status === 'fuzzy'">
                                                    <select class="w-full rounded-lg border border-amber-300 bg-amber-50 text-xs py-1.5"
                                                            :value="links[row.impact_shooter_id] || ''"
                                                            @change="links[row.impact_shooter_id] = $event.target.value">
                                                        <option value="">Confirm link…</option>
                                                        <template x-for="c in row.fuzzy_candidates" :key="c.key">
                                                            <option :value="c.key" x-text="c.name+' ('+c.score+'%)'"></option>
                                                        </template>
                                                        <option value="assign_later">Assign later (import stub)</option>
                                                        <option value="skip">Skip this shooter</option>
                                                    </select>
                                                </template>
                                                <template x-if="row.match_status === 'unmatched'">
                                                    <select class="w-full rounded-lg border border-violet-200 bg-violet-50 text-xs py-1.5"
                                                            :value="links[row.impact_shooter_id] || 'auto'"
                                                            @change="links[row.impact_shooter_id] = $event.target.value">
                                                        <option value="auto" x-text="row.action === 'walk_in_member' ? 'Auto: member walk-in' : 'Auto: non-member stub'"></option>
                                                        <option value="assign_later">Assign later (import stub)</option>
                                                        <option value="skip">Skip this shooter</option>
                                                    </select>
                                                </template>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="text-[11px] font-medium rounded-full px-2 py-0.5"
                                                      :class="{
                                                        'bg-emerald-100 text-emerald-800': row.action === 'import' && links[row.impact_shooter_id] !== 'assign_later',
                                                        'bg-amber-100 text-amber-900': row.action === 'import_and_collect' && links[row.impact_shooter_id] !== 'assign_later',
                                                        'bg-sky-100 text-sky-900': (row.action === 'walk_in_member' || row.action === 'walk_in_lapsed') && links[row.impact_shooter_id] !== 'assign_later' && links[row.impact_shooter_id] !== 'skip',
                                                        'bg-stone-100 text-stone-600': row.action === 'non_member_no_log' && links[row.impact_shooter_id] !== 'assign_later' && links[row.impact_shooter_id] !== 'skip',
                                                        'bg-violet-100 text-violet-900': links[row.impact_shooter_id] === 'assign_later',
                                                        'bg-stone-200 text-stone-500': links[row.impact_shooter_id] === 'skip',
                                                      }"
                                                      x-text="links[row.impact_shooter_id] === 'assign_later' ? 'Assign later' : (links[row.impact_shooter_id] === 'skip' ? 'Skip' : actionLabel(row.action))"></span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit" :disabled="!canImport"
                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-40 transition">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                            Import from Impact
                        </button>
                        <p class="text-xs text-stone-500" x-show="unconfirmedFuzzy > 0">
                            Confirm <span class="font-semibold" x-text="unconfirmedFuzzy"></span> fuzzy name match(es) first.
                        </p>
                    </div>
                </div>
            </form>
        </div>

        {{-- ═══════════════ CSV (existing) ═══════════════ --}}
        <div x-show="mode === 'csv'" class="space-y-6">
            <details class="rounded-xl border border-sky-200 bg-sky-50/50 p-4 open:pb-4">
                <summary class="cursor-pointer font-semibold text-sky-900 select-none">CSV format &amp; example downloads</summary>
                <div class="mt-3 text-sm text-sky-900 space-y-2">
                    <p>The CSV must have a header row. <strong>Required:</strong> shooter identity (<code class="rounded bg-white px-1.5 py-0.5 text-xs">shooter_name</code>, or <code class="rounded bg-white px-1.5 py-0.5 text-xs">first</code> + <code class="rounded bg-white px-1.5 py-0.5 text-xs">last</code>) and a numeric total (<code class="rounded bg-white px-1.5 py-0.5 text-xs">raw_score</code>, <code class="rounded bg-white px-1.5 py-0.5 text-xs">Impacts</code>, <code class="rounded bg-white px-1.5 py-0.5 text-xs">Total</code>, etc.).</p>
                    <p>Preview before import. Confirm fuzzy names, choose <strong>Assign later</strong> when you still need to check who someone is, or skip a row. Exact matches land on that entry. You can correct the hit total before it is saved.</p>
                    <div class="mt-3 pt-3 border-t border-sky-200/70">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <a href="{{ route('score-imports.template', ['variant' => 'basic']) }}" class="flex items-start gap-2 rounded-lg border border-sky-200 bg-white px-3 py-2 hover:border-sky-400 hover:bg-sky-50 transition text-xs">
                                <span class="font-semibold text-sky-900">Basic (totals only)</span>
                            </a>
                            <a href="{{ route('score-imports.template', ['variant' => 'detailed']) }}" class="flex items-start gap-2 rounded-lg border border-sky-200 bg-white px-3 py-2 hover:border-sky-400 hover:bg-sky-50 transition text-xs">
                                <span class="font-semibold text-sky-900">Detailed (with stages)</span>
                            </a>
                            <a href="{{ route('score-imports.template', ['variant' => 'impact']) }}" class="flex items-start gap-2 rounded-lg border border-sky-200 bg-white px-3 py-2 hover:border-sky-400 hover:bg-sky-50 transition text-xs">
                                <span class="font-semibold text-sky-900">Impact export style</span>
                            </a>
                        </div>
                    </div>
                </div>
            </details>

            <form method="POST" action="{{ route('score-imports.store') }}" enctype="multipart/form-data" class="space-y-6" @submit="onCsvSubmit">
                @csrf
                <input type="hidden" name="assignment_review" :value="csvPreview ? '1' : '0'">
                <template x-for="row in (csvPreview?.shooters || [])" :key="'csv-link-'+row.row_key">
                    <span>
                        <input type="hidden" :name="'links['+row.row_key+']'" :value="csvLinkValue(row)">
                        <input type="hidden" :name="'score_overrides['+row.row_key+'][impacts]'" :value="csvImpacts(row)">
                    </span>
                </template>
                <div class="rounded-2xl border border-stone-200 bg-white shadow-sm p-6 space-y-5">
                    <div>
                        <label for="csv_match_id" class="block text-sm font-medium text-stone-700 mb-1">Match <span class="text-red-500">*</span></label>
                        <select name="match_id" id="csv_match_id" required x-model="matchId" @change="resetCsvPreview()" class="block w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">Select match…</option>
                            @foreach ($matches as $match)
                                <option value="{{ $match->id }}">
                                    {{ $match->name }} ({{ $match->match_date->format('d M Y') }}{{ $match->isMultiDay() ? ' – 2-day' : '' }})
                                </option>
                            @endforeach
                        </select>
                        @error('match_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="source_type" class="block text-sm font-medium text-stone-700 mb-1">Source <span class="text-red-500">*</span></label>
                        <select name="source_type" id="source_type" required class="block w-full rounded-lg border border-stone-300 text-sm py-2.5 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="csv" @selected(old('source_type', 'csv') === 'csv')>CSV file</option>
                            <option value="practiscore" @selected(old('source_type') === 'practiscore')>PractiScore export</option>
                            <option value="impact" @selected(old('source_type') === 'impact')>Impact scoring (CSV export)</option>
                            <option value="manual" @selected(old('source_type') === 'manual')>Manual entry</option>
                            <option value="other" @selected(old('source_type') === 'other')>Other</option>
                        </select>
                    </div>

                    <div x-show="isTwoDayNational" class="rounded-xl border border-blue-200 bg-blue-50/50 p-4">
                        <label class="block text-sm font-medium text-stone-900 mb-2">What does this CSV contain? <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition"
                                   :class="scoreScope === 'day1' ? 'border-blue-500 bg-white ring-1 ring-blue-500' : 'border-stone-200 bg-white'">
                                <input type="radio" name="score_scope" value="day1" x-model="scoreScope" class="mt-0.5 text-blue-600">
                                <span class="text-sm"><span class="block font-semibold">Day 1 scores</span></span>
                            </label>
                            <label class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition"
                                   :class="scoreScope === 'overall' ? 'border-blue-500 bg-white ring-1 ring-blue-500' : 'border-stone-200 bg-white'">
                                <input type="radio" name="score_scope" value="overall" x-model="scoreScope" class="mt-0.5 text-blue-600">
                                <span class="text-sm"><span class="block font-semibold">Overall scores</span></span>
                            </label>
                        </div>
                        @error('score_scope') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="file" class="block text-sm font-medium text-stone-700 mb-1">CSV File <span class="text-red-500">*</span></label>
                        <input type="file" name="file" id="file" accept=".csv,text/csv" required @change="resetCsvPreview()"
                               class="block w-full text-sm text-stone-600 file:mr-4 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-4 file:py-2.5 file:text-sm file:font-medium file:text-emerald-700 hover:file:bg-emerald-100 cursor-pointer"/>
                        @error('file') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-4">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="hidden" name="replace_existing" value="0">
                            <input type="checkbox" name="replace_existing" value="1" @checked(old('replace_existing')) class="mt-1 rounded border-amber-400 text-amber-600">
                            <div class="text-sm">
                                <span class="font-semibold text-stone-900">Replace existing scores for the target match</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div x-show="csvPreviewError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" x-text="csvPreviewError"></div>

                <div x-show="csvPreview" class="rounded-2xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 bg-stone-50/80 px-5 py-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-stone-400">Assign scores to entries</p>
                            <p class="text-sm text-stone-600 mt-0.5">Same corrections as Impact. Confirm a fuzzy name, assign later, or skip. The imported name stays on the scoreboard.</p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="rounded-full bg-emerald-100 text-emerald-800 px-2.5 py-1 font-medium" x-text="(csvPreview?.summary?.exact||0)+' matched'"></span>
                            <span class="rounded-full bg-amber-100 text-amber-900 px-2.5 py-1 font-medium" x-text="(csvPreview?.summary?.fuzzy||0)+' need confirm'"></span>
                            <span class="rounded-full bg-stone-200 text-stone-700 px-2.5 py-1 font-medium" x-text="(csvPreview?.summary?.unmatched||0)+' unmatched'"></span>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-stone-500">
                                <tr>
                                    <th class="px-4 py-2.5 font-semibold">Shooter</th>
                                    <th class="px-4 py-2.5 font-semibold">Division</th>
                                    <th class="px-4 py-2.5 font-semibold text-right">Hits <span class="font-normal normal-case text-stone-400">(editable)</span></th>
                                    <th class="px-4 py-2.5 font-semibold">Link</th>
                                    <th class="px-4 py-2.5 font-semibold">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                <template x-for="row in (csvPreview?.shooters || [])" :key="'csv-row-'+row.row_key">
                                    <tr class="align-top hover:bg-stone-50/60">
                                        <td class="px-4 py-3 font-medium text-stone-900" x-text="row.name"></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center rounded-md bg-stone-100 px-2 py-0.5 text-xs font-medium capitalize text-stone-800" x-text="row.division_slug"></span>
                                            <span class="block text-[10px] text-stone-400 mt-0.5" x-text="row.division_source === 'registration' ? 'from entry' : 'from CSV'"></span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <input type="number" min="0" max="9999" step="1"
                                                   class="w-20 rounded-lg border text-right text-sm tabular-nums py-1 px-2 focus:ring-emerald-500 focus:border-emerald-500"
                                                   :class="Number(csvImpacts(row)) !== Number(row.impacts) ? 'border-amber-400 bg-amber-50 font-semibold text-amber-900' : 'border-stone-200 text-stone-900'"
                                                   :value="csvImpacts(row)"
                                                   @input="setCsvOverride(row.row_key, $event.target.value, row.impacts)">
                                        </td>
                                        <td class="px-4 py-3 min-w-[12rem]">
                                            <template x-if="row.match_status === 'exact'">
                                                <span class="text-xs text-emerald-700 font-medium" x-text="'✓ '+(row.registration_name || 'entry')"></span>
                                            </template>
                                            <template x-if="row.match_status === 'fuzzy'">
                                                <select class="w-full rounded-lg border border-amber-300 bg-amber-50 text-xs py-1.5"
                                                        :value="csvLinks[row.row_key] || ''"
                                                        @change="csvLinks[row.row_key] = $event.target.value">
                                                    <option value="">Confirm link…</option>
                                                    <template x-for="c in row.fuzzy_candidates" :key="c.key">
                                                        <option :value="c.key" x-text="c.name+' ('+c.score+'%)'"></option>
                                                    </template>
                                                    <option value="assign_later">Assign later (import stub)</option>
                                                    <option value="skip">Skip this shooter</option>
                                                </select>
                                            </template>
                                            <template x-if="row.match_status === 'unmatched'">
                                                <select class="w-full rounded-lg border border-violet-200 bg-violet-50 text-xs py-1.5"
                                                        :value="csvLinks[row.row_key] || 'auto'"
                                                        @change="csvLinks[row.row_key] = $event.target.value">
                                                    <option value="auto" x-text="row.action === 'walk_in_member' ? 'Auto: member walk-in' : 'Auto: non-member stub'"></option>
                                                    <option value="assign_later">Assign later (import stub)</option>
                                                    <option value="skip">Skip this shooter</option>
                                                </select>
                                            </template>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="text-[11px] font-medium rounded-full px-2 py-0.5"
                                                  :class="{
                                                    'bg-emerald-100 text-emerald-800': row.action === 'import' && csvLinks[row.row_key] !== 'assign_later',
                                                    'bg-amber-100 text-amber-900': row.action === 'import_and_collect' && csvLinks[row.row_key] !== 'assign_later',
                                                    'bg-sky-100 text-sky-900': (row.action === 'walk_in_member' || row.action === 'walk_in_lapsed') && csvLinks[row.row_key] !== 'assign_later' && csvLinks[row.row_key] !== 'skip',
                                                    'bg-stone-100 text-stone-600': row.action === 'non_member_no_log' && csvLinks[row.row_key] !== 'assign_later' && csvLinks[row.row_key] !== 'skip',
                                                    'bg-violet-100 text-violet-900': csvLinks[row.row_key] === 'assign_later',
                                                    'bg-stone-200 text-stone-500': csvLinks[row.row_key] === 'skip',
                                                  }"
                                                  x-text="csvLinks[row.row_key] === 'assign_later' ? 'Assign later' : (csvLinks[row.row_key] === 'skip' ? 'Skip' : actionLabel(row.action))"></span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" @click="runCsvPreview()" :disabled="csvPreviewLoading || !matchId"
                            class="inline-flex items-center gap-2 rounded-xl bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-800 disabled:opacity-40 transition">
                        <span x-text="csvPreviewLoading ? 'Reading CSV…' : 'Preview & assign'"></span>
                    </button>
                    <button type="submit" x-show="csvPreview" :disabled="!canCsvImport"
                            class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-40 transition">
                        Import CSV
                    </button>
                    <p class="text-xs text-stone-500" x-show="unconfirmedCsvFuzzy > 0">
                        Confirm <span class="font-semibold" x-text="unconfirmedCsvFuzzy"></span> fuzzy name match(es) first.
                    </p>
                </div>
            </form>
        </div>
    </div>

    <script>
        function scoreImportWizard(cfg) {
            return {
                mode: cfg.initialMode === 'csv' ? 'csv' : 'impact',
                matchMeta: cfg.matchMeta || {},
                matchId: cfg.preselectedMatchId ? String(cfg.preselectedMatchId) : '',
                impactRef: '',
                scoreScope: '',
                day1StageIds: [],
                replaceExisting: true,
                previewUrl: cfg.previewUrl,
                importUrl: cfg.importUrl,
                csrf: cfg.csrf,
                preview: null,
                previewLoading: false,
                previewError: '',
                links: {},
                scoreOverrides: {},
                expanded: {},
                csvPreviewUrl: cfg.csvPreviewUrl,
                csvPreview: null,
                csvPreviewLoading: false,
                csvPreviewError: '',
                csvLinks: {},
                csvOverrides: {},
                get isTwoDayNational() {
                    return this.matchId && this.matchMeta[this.matchId]?.is_two_day_national;
                },
                get unconfirmedFuzzy() {
                    if (!this.preview?.shooters) return 0;
                    return this.preview.shooters.filter(r =>
                        r.match_status === 'fuzzy'
                        && !this.links[r.impact_shooter_id]
                    ).length;
                },
                get unconfirmedCsvFuzzy() {
                    if (!this.csvPreview?.shooters) return 0;
                    return this.csvPreview.shooters.filter(r =>
                        r.match_status === 'fuzzy' && !this.csvLinks[r.row_key]
                    ).length;
                },
                get canCsvImport() {
                    return !!this.csvPreview && this.unconfirmedCsvFuzzy === 0
                        && (!this.isTwoDayNational || !!this.scoreScope);
                },
                get canImport() {
                    return !!this.preview && this.unconfirmedFuzzy === 0
                        && (!this.isTwoDayNational || !!this.scoreScope)
                        && (this.scoreScope !== 'day1' || this.day1StageIds.length > 0);
                },
                resetPreview() {
                    this.preview = null;
                    this.previewError = '';
                    this.links = {};
                    this.scoreOverrides = {};
                    this.day1StageIds = [];
                    this.expanded = {};
                },
                overrideImpacts(row) {
                    const o = this.scoreOverrides[row.impact_shooter_id];
                    return (o && o.impacts !== undefined && o.impacts !== '') ? o.impacts : row.impacts;
                },
                overrideDay1(row) {
                    const o = this.scoreOverrides[row.impact_shooter_id];
                    return (o && o.day1_impacts !== undefined && o.day1_impacts !== '') ? o.day1_impacts : row.day1_impacts;
                },
                overrideFields() {
                    const fields = [];
                    Object.entries(this.scoreOverrides || {}).forEach(([sid, values]) => {
                        ['impacts', 'day1_impacts', 'note'].forEach((field) => {
                            if (!values || values[field] === undefined || values[field] === '') return;
                            fields.push({
                                key: sid + '-' + field,
                                name: 'score_overrides[' + sid + '][' + field + ']',
                                value: values[field],
                            });
                        });
                    });
                    return fields;
                },
                stageDayGroups() {
                    const stages = this.preview?.impact_stages || [];
                    const groups = {};
                    stages.forEach((stage) => {
                        const day = Number(stage.day) || 1;
                        if (!groups[day]) {
                            groups[day] = { day, label: 'Day ' + day, stages: [] };
                        }
                        groups[day].stages.push(stage);
                    });
                    return Object.values(groups);
                },
                stageDaySummary() {
                    const days = this.preview?.stage_days || [];
                    if (days.length < 2) {
                        return 'The Day 1 score is the sum of each selected stage, not the match total.';
                    }
                    const bits = days.map((day) => 'Day ' + day.day + ': ' + day.count + (day.count === 1 ? ' stage' : ' stages'));
                    return bits.join(' · ') + '. Day 1 is ticked already. The score is each of those stage hits added up. Untick a stage to leave it out.';
                },
                isAdjusted(row) {
                    return Number(this.overrideImpacts(row)) !== Number(row.impacts)
                        || Number(this.overrideDay1(row)) !== Number(row.day1_impacts);
                },
                setOverride(sid, field, value, original) {
                    const next = { ...(this.scoreOverrides[sid] || {}) };
                    if (value === '' || Number(value) === Number(original)) {
                        delete next[field];
                    } else {
                        next[field] = value;
                    }
                    if (!next.impacts && next.impacts !== 0 && !next.day1_impacts && next.day1_impacts !== 0) {
                        const copy = { ...this.scoreOverrides };
                        delete copy[sid];
                        this.scoreOverrides = copy;
                        return;
                    }
                    next.note = 'adjusted from Impact';
                    this.scoreOverrides = { ...this.scoreOverrides, [sid]: next };
                },
                onScopeChange() {
                    if (this.scoreScope !== 'day1') this.day1StageIds = [];
                    if (this.preview) this.runPreview(this.scoreScope === 'day1');
                },
                toggleDay1Stage(id) {
                    id = Number(id);
                    if (this.day1StageIds.includes(id)) {
                        this.day1StageIds = this.day1StageIds.filter(x => x !== id);
                    } else {
                        this.day1StageIds = [...this.day1StageIds, id];
                    }
                    this.recomputeDay1Totals();
                },
                recomputeDay1Totals() {
                    if (!this.preview?.shooters) return;
                    const want = new Set(this.day1StageIds.map(Number));
                    const shooters = this.preview.shooters.map((row) => {
                        if (!Array.isArray(row.stages) || row.stages.length === 0) return row;
                        const day1 = row.stages.reduce((sum, stage) => {
                            return want.has(Number(stage.id)) ? sum + Number(stage.hits || 0) : sum;
                        }, 0);
                        return { ...row, day1_impacts: day1 };
                    });
                    this.preview = { ...this.preview, shooters, day1_stage_ids: [...this.day1StageIds] };
                },
                toggleStages(row) {
                    if (!row.stages?.length) {
                        this.runPreview(true).then(() => {
                            this.expanded[row.impact_shooter_id] = !this.expanded[row.impact_shooter_id];
                        });
                        return;
                    }
                    this.expanded[row.impact_shooter_id] = !this.expanded[row.impact_shooter_id];
                },
                actionLabel(a) {
                    return ({
                        import: 'Import',
                        import_and_collect: 'Import · collect fee',
                        walk_in_member: 'Walk-in member',
                        walk_in_lapsed: 'Walk-in lapsed',
                        non_member_no_log: 'Non-member · no log',
                    })[a] || a;
                },
                async runPreview(withStages = false) {
                    this.previewLoading = true;
                    this.previewError = '';
                    try {
                        const body = {
                            match_id: this.matchId,
                            impact_ref: this.impactRef,
                            score_scope: this.scoreScope || null,
                            day1_stage_ids: this.day1StageIds,
                            with_stages: withStages || this.scoreScope === 'day1',
                        };
                        const res = await fetch(this.previewUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                            },
                            body: JSON.stringify(body),
                        });
                        const data = await res.json();
                        if (!data.ok) {
                            this.previewError = data.message || 'Preview failed.';
                            this.preview = null;
                            return;
                        }
                        this.preview = data.preview;
                        if (this.scoreScope === 'day1' && this.day1StageIds.length === 0) {
                            const suggested = (data.preview.day1_stage_ids || []).map(Number).filter(Boolean);
                            if (suggested.length) this.day1StageIds = suggested;
                        }
                        // Preserve confirmed fuzzy links
                        const next = { ...this.links };
                        (data.preview.shooters || []).forEach(r => {
                            if (r.match_status === 'exact' && r.registration_id) {
                                next[r.impact_shooter_id] = 'reg:' + r.registration_id;
                            }
                        });
                        this.links = next;
                    } catch (e) {
                        this.previewError = e.message || 'Network error.';
                    } finally {
                        this.previewLoading = false;
                    }
                },
                onImpactSubmit(e) {
                    if (!this.canImport) {
                        e.preventDefault();
                        return;
                    }
                },
                resetCsvPreview() {
                    this.csvPreview = null;
                    this.csvPreviewError = '';
                    this.csvLinks = {};
                    this.csvOverrides = {};
                },
                csvImpacts(row) {
                    const value = this.csvOverrides[row.row_key];
                    return (value !== undefined && value !== '') ? value : row.impacts;
                },
                csvLinkValue(row) {
                    if (row.match_status === 'exact' && row.registration_id) {
                        return 'reg:' + row.registration_id;
                    }
                    if (row.match_status === 'unmatched') {
                        return this.csvLinks[row.row_key] || 'auto';
                    }
                    return this.csvLinks[row.row_key] || '';
                },
                setCsvOverride(key, value, original) {
                    if (value === '' || Number(value) === Number(original)) {
                        const copy = { ...this.csvOverrides };
                        delete copy[key];
                        this.csvOverrides = copy;
                        return;
                    }
                    this.csvOverrides = { ...this.csvOverrides, [key]: value };
                },
                onCsvSubmit(e) {
                    if (!this.canCsvImport) {
                        e.preventDefault();
                    }
                },
                async runCsvPreview() {
                    const input = document.getElementById('file');
                    const file = input?.files?.[0];
                    if (!this.matchId || !file) {
                        this.csvPreviewError = 'Choose a match and a CSV file first.';
                        return;
                    }
                    this.csvPreviewLoading = true;
                    this.csvPreviewError = '';
                    try {
                        const body = new FormData();
                        body.append('match_id', this.matchId);
                        body.append('file', file);
                        const res = await fetch(this.csvPreviewUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                            },
                            body,
                        });
                        const data = await res.json();
                        if (!data.ok) {
                            this.csvPreviewError = data.message || 'Preview failed.';
                            this.csvPreview = null;
                            return;
                        }
                        this.csvPreview = data.preview;
                        const next = { ...this.csvLinks };
                        (data.preview.shooters || []).forEach(r => {
                            if (r.match_status === 'exact' && r.registration_id) {
                                next[r.row_key] = 'reg:' + r.registration_id;
                            }
                        });
                        this.csvLinks = next;
                    } catch (e) {
                        this.csvPreviewError = e.message || 'Network error.';
                    } finally {
                        this.csvPreviewLoading = false;
                    }
                },
            };
        }
    </script>
</x-layouts.app>
