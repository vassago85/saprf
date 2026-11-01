@php
    $pendingCount = $wizard['pending_link_count'];
    $missingDivCount = $wizard['missing_division_count'];
    $zeroCount = $wizard['unconfirmed_zero_count'];
    $totalOpen = $pendingCount + $missingDivCount + $zeroCount;
@endphp

<div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
    <div class="flex items-start gap-3">
        <flux:icon.user-group class="mt-0.5 size-6 shrink-0 text-amber-700" />
        <div class="flex-1">
            <h2 class="font-heading text-lg font-bold text-amber-950">Step 2 · Correct names and allocations</h2>
            <p class="mt-1 text-sm text-amber-900">
                Link anyone who didn't match an entry, pick a division where one is missing, and resolve
                each zero score. {{ $totalOpen }} {{ \Illuminate\Support\Str::plural('row', $totalOpen) }}
                still {{ $totalOpen === 1 ? 'needs' : 'need' }} attention before you can continue.
            </p>
        </div>
    </div>
</div>

{{-- 2a. Rows waiting to be linked to a real match entry. --}}
@if($pendingLinkScores->isNotEmpty())
    <div class="mt-6 rounded-2xl border border-violet-200 bg-gradient-to-br from-violet-50/80 via-white to-white p-6 shadow-sm">
        <div class="mb-4 flex items-start gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-700 text-white">
                <flux:icon.arrows-right-left class="size-5" />
            </div>
            <div>
                <h3 class="font-heading text-base font-bold text-violet-950">
                    Link to entry · {{ $pendingLinkScores->count() }}
                </h3>
                <p class="mt-0.5 text-sm text-violet-900/80">
                    Attach the real match entry (member or non-member). Entry division wins; the imported
                    name stays as shown on the scoreboard.
                </p>
            </div>
        </div>

        @if($linkRegistrations->isEmpty())
            <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                No active entries on this match to link to. Add the shooter on the entries list first
                (member or non-member walk-in), then return here.
            </p>
        @else
            <ul class="space-y-3">
                @foreach($pendingLinkScores as $pending)
                    <li class="rounded-xl border border-violet-200/80 bg-white px-4 py-3">
                        <form method="POST" action="{{ route('scores.link-impact-member', $pending) }}"
                              class="flex flex-wrap items-end gap-3">
                            @csrf
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-stone-900">{{ $pending->shooter_name }}</p>
                                <p class="mt-0.5 text-xs text-stone-500">
                                    Scoreboard name ·
                                    {{ number_format((float) ($pending->raw_score ?? $pending->day1_raw_score ?? 0), 1) }} hits
                                    · {{ $pending->status }}
                                </p>
                            </div>
                            <div class="w-full sm:w-80">
                                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-stone-400">
                                    Match entry
                                </label>
                                <select name="registration_id" required
                                        class="w-full rounded-lg border border-violet-300 py-2 text-sm focus:border-violet-500 focus:ring-violet-500">
                                    <option value="">Select entry…</option>
                                    @foreach($linkRegistrations as $reg)
                                        @php
                                            $entryLabel = $reg->user?->name ?: $reg->shooter_name ?: 'Unnamed entry';
                                            $cat = $reg->feeCategoryLabel();
                                        @endphp
                                        <option value="{{ $reg->id }}">
                                            {{ $entryLabel }}
                                            · {{ $cat }}
                                            @if($reg->division) · {{ $reg->division->name }} @endif
                                            · {{ $reg->payment_status ?? '—' }}
                                            @if(! $reg->user_id) · name-only @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-violet-800">
                                Link
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

{{-- 2b. Rows with no division. The MD picks one before the step closes. --}}
@if($missingDivisionScores->isNotEmpty())
    <div class="mt-6 rounded-2xl border border-amber-200 bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-start gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-600 text-white">
                <flux:icon.rectangle-stack class="size-5" />
            </div>
            <div>
                <h3 class="font-heading text-base font-bold text-stone-900">
                    Missing division · {{ $missingDivisionScores->count() }}
                </h3>
                <p class="mt-0.5 text-sm text-stone-600">
                    These rows came in without a division. Pick one from the match's active divisions
                    — standings recalculate immediately.
                </p>
            </div>
        </div>

        <ul class="space-y-3">
            @foreach($missingDivisionScores as $score)
                <li class="rounded-xl border border-stone-200 bg-stone-50/60 px-4 py-3">
                    <form method="POST" action="{{ route('matches.scores.allocation', ['match' => $match, 'score' => $score]) }}"
                          class="flex flex-wrap items-end gap-3">
                        @csrf
                        @method('PUT')
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-stone-900">{{ $score->shooter_name ?: ($score->user?->name ?? 'Unknown') }}</p>
                            <p class="mt-0.5 text-xs text-stone-500">
                                {{ number_format((float) $score->raw_score, 1) }} hits · {{ $score->status }}
                            </p>
                        </div>
                        <div class="w-full sm:w-64">
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-stone-400">
                                Division
                            </label>
                            <select name="division_id" required
                                    class="w-full rounded-lg border border-stone-300 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                <option value="">Select division…</option>
                                @foreach($divisions as $division)
                                    <option value="{{ $division->id }}">{{ $division->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit"
                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                            Save
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    </div>
@endif

{{-- 2c. Zero-score rows the MD still has to resolve. --}}
@if($unconfirmedZeroScores->isNotEmpty())
    <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/60 p-6 shadow-sm">
        <div class="mb-4 flex items-start gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white">
                <flux:icon.exclamation-triangle class="size-5" />
            </div>
            <div>
                <h3 class="font-heading text-base font-bold text-stone-900">
                    Confirm zero scores · {{ $unconfirmedZeroScores->count() }}
                </h3>
                <p class="mt-0.5 text-sm text-stone-600">
                    A zero can mean they genuinely zeroed, or that they were listed by mistake and
                    never turned up. Confirm each row so results and season logs stay honest.
                </p>
            </div>
        </div>

        <ul class="space-y-2">
            @foreach($unconfirmedZeroScores as $zero)
                <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-stone-900">
                            {{ $zero->user?->name ?? $zero->shooter_name ?? '—' }}
                        </p>
                        <p class="text-xs text-stone-500">
                            {{ $zero->division?->name ?? 'No division' }}
                            @if($zero->placement)
                                · Listed placement {{ $zero->placement }}
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST"
                              action="{{ route('score-imports.scores.confirm-participation', ['scoreImport' => $zero->score_import_id, 'score' => $zero]) }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                                <flux:icon.check class="size-4" />
                                Yes, participated
                            </button>
                        </form>
                        <form method="POST"
                              action="{{ route('score-imports.scores.mark-absent', ['scoreImport' => $zero->score_import_id, 'score' => $zero]) }}"
                              onsubmit="return confirm('Remove this shooter from the match results? Their score row will be deleted.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                                <flux:icon.x-mark class="size-4" />
                                No, remove
                            </button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Nothing left to resolve on this step — but the wizard still shows us here
     because the walk-in step hasn't been reached yet. This is a transient
     state that only shows if the state() derivation ever drops us here with
     no open rows (e.g. the controller swapped a page cache). Nudge forward. --}}
@if($pendingLinkScores->isEmpty() && $missingDivisionScores->isEmpty() && $unconfirmedZeroScores->isEmpty())
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-900">
        <p class="font-semibold">Names and allocations are clean.</p>
        <p class="mt-1 text-emerald-800">Refresh to move on to the next step.</p>
    </div>
@endif
