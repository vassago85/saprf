{{--
    Section 7 of 7 — Scoring.
    Everyone counts (admin/exco/dev only), the Day-1-dual-count option
    for Nationals (hidden otherwise but preserved as a hidden input so
    the saved value survives a save), and the Impact live scores feed.
--}}
@php
    /** @var \App\Models\MatchEvent|null $match */
    $match = $match ?? null;
    $user = auth()->user();

    $canToggleEveryoneCounts = $user?->hasAnyRole(['owner', 'admin', 'exco', 'developer']);

    $everyoneCountsValue = (bool) old('everyone_counts', $match?->everyone_counts);
    $dualCountValue = (bool) old('also_counts_for_provincial', $match?->also_counts_for_provincial);
    $provincialColumns = old('provincial_stage_columns', $match?->provincial_stage_columns);
    $impactRefValue = old('impact_ref', $match?->impact_match_id);
    $impactLiveSyncValue = (bool) filter_var(old('impact_live_sync', $match?->impact_live_sync), FILTER_VALIDATE_BOOLEAN);

    $defaultSeriesLevel = $match?->series_level ?? 'club';
@endphp
<section id="scoring" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-5"
         x-data="{
            seriesLevel: '{{ old('series_level', $defaultSeriesLevel) }}',
            dualCount: {{ $dualCountValue ? 'true' : 'false' }},
         }"
         x-init="
            const el = document.getElementById('series_level');
            if (el) {
                el.addEventListener('change', () => { seriesLevel = el.value; });
                seriesLevel = el.value;
            }
         ">
    <header>
        <h3 class="text-sm font-semibold text-stone-900">Scoring</h3>
        <p class="mt-0.5 text-xs text-stone-500">How this match's scores feed the pools and whether the live Impact pull is on.</p>
    </header>

    <div class="mt-4 space-y-4">
        @if($canToggleEveryoneCounts)
            <div class="rounded-lg border border-stone-200 bg-stone-50 p-3">
                <label class="flex items-start gap-2">
                    <input type="hidden" name="everyone_counts" value="0">
                    <input type="checkbox" name="everyone_counts" value="1" @checked($everyoneCountsValue)
                           class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                    <span>
                        <span class="block text-sm font-medium text-stone-800">All shooters count regardless of membership</span>
                        <span class="mt-0.5 block text-xs text-stone-500">Use for imported historical matches (e.g. Day-1 provincial extracts) where the organiser ruled everyone counts. Every score on this match evaluates to Eligible on save/re-import/re-evaluation, instead of running the usual membership check.</span>
                    </span>
                </label>
            </div>
        @endif

        {{-- Day-1 dual-count — only meaningful on Nationals. When the series
             level is anything else we render *no* field at all so the save
             payload omits `also_counts_for_provincial` and the controller's
             `$match->update($validated)` leaves whatever was stored
             untouched. Switching series level mid-edit swaps the whole
             block via `<template x-if>` — Alpine replaces the DOM so there
             is never a hidden-but-stale value sitting in the form. --}}
        <template x-if="seriesLevel === 'national'">
            <div>
                <label class="flex items-start gap-2">
                    <input type="hidden" name="also_counts_for_provincial" value="0">
                    <input type="checkbox" name="also_counts_for_provincial" value="1" x-model="dualCount" @checked($dualCountValue)
                           class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                    <span>
                        <span class="block text-sm font-medium text-stone-800">Day 1 also counts as a Provincial score</span>
                        <span class="mt-0.5 block text-xs text-stone-500">Recommended for all 2-day nationals. Under PR22 pooled scoring, each shooter's <strong>Day 1 total</strong> is used as a provincial-pool contribution (in addition to counting Day 1 + Day 2 for the national pool). MDs enter Day 1 and Day 2 separately when scoring.</span>
                    </span>
                </label>

                <div x-show="dualCount" x-cloak class="mt-4 space-y-3 rounded-lg border border-stone-200 bg-stone-50 p-4">
                    <div>
                        <label for="provincial_stage_columns" class="block text-xs font-medium text-stone-600 mb-1">Provincial stage columns</label>
                        <input type="text" name="provincial_stage_columns" id="provincial_stage_columns" value="{{ $provincialColumns }}"
                               placeholder="e.g. stage_1, stage_2, stage_3, stage_4, stage_5"
                               class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                        <p class="mt-1 text-xs text-stone-400">Comma-separated CSV column names for the stages that count for provincial. These must match the column headers in the PractiScore/Impact export. The system will sum these values per shooter.</p>
                        @error('provincial_stage_columns') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    @if($match && $match->scores()->whereNotNull('raw_meta')->exists())
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
        </template>

        <div class="rounded-lg border border-stone-200 bg-white p-4 space-y-4">
            <div>
                <h4 class="text-sm font-semibold text-stone-900">Live scores from Impact</h4>
                <p class="mt-0.5 text-xs text-stone-500 leading-relaxed">
                    Paste the link to this match on Impact — the match page itself (/match/view?id=…) or the results page. On the day it is shot, SAPRF pulls the scoreboard every 5 minutes.
                    A shooter is added only when their Impact name matches an entry exactly. Anyone else stays off the board until you link them with the normal Impact import.
                    A score you have adjusted is left as you saved it. The pull stops when Impact marks the match finished. It does not close the match or email anyone.
                </p>
            </div>

            <div>
                <label for="impact_ref" class="block text-xs font-medium text-stone-600 mb-1">Impact match link or match number</label>
                <input type="text" name="impact_ref" id="impact_ref" value="{{ $impactRefValue }}"
                       placeholder="https://www.impactscoring.net/match/view?id=10558"
                       class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                @error('impact_ref') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-start gap-2">
                <input type="hidden" name="impact_live_sync" value="0">
                <input type="checkbox" name="impact_live_sync" value="1" @checked($impactLiveSyncValue)
                       class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                <span>
                    <span class="block text-sm font-medium text-stone-800">Pull live scores every 5 minutes on match day</span>
                    <span class="mt-0.5 block text-xs text-stone-500">Starts on the start date and keeps going through the end date. Outside those days nothing is pulled.</span>
                </span>
            </label>

            @if($match && ($match->impact_live_synced_at || $match->impact_live_ended_at || $match->impact_live_error))
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
    </div>
</section>
