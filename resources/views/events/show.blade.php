@php
    $eventSeoDescription = filled($match->description)
        ? \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($match->description))), 160)
        : collect([
            $match->name,
            $match->match_type,
            $match->series_level ? ucfirst(str_replace('_', ' ', $match->series_level)) : null,
            $match->match_date?->format('j F Y'),
            $match->province?->name ?? $match->city,
        ])->filter()->implode(' · ').'. Official SAPRF match listing — register, view entries, and results.';
@endphp
<x-layouts.public :title="$match->name . ' - SAPRF'" :description="$eventSeoDescription" current="events" sponsor-placement="match_pages">
    <div class="bg-stone-50 min-h-screen">
        {{-- Hero / Header --}}
        <div class="bg-white border-b border-stone-200">
            @if($match->posterUrl())
                {{-- Full flyer: keep the vertical composition intact (logos, orange bar, venues). --}}
                <div class="border-b border-stone-200 bg-gradient-to-b from-stone-100 to-stone-50">
                    <div class="mx-auto flex max-w-6xl justify-center px-4 py-6 sm:px-6 sm:py-8">
                        <img src="{{ $match->posterUrl() }}"
                             alt="{{ $match->name }} promotional poster"
                             class="w-full max-w-sm sm:max-w-md rounded-xl shadow-[0_8px_30px_-12px_rgb(0_0_0/0.35)] ring-1 ring-black/5">
                    </div>
                </div>
            @elseif($match->bannerUrl())
                <div class="w-full max-h-64 sm:max-h-80 overflow-hidden bg-stone-100">
                    <img src="{{ $match->bannerUrl() }}"
                         alt="{{ $match->name }}"
                         class="h-full max-h-64 w-full object-cover object-[center_28%] sm:max-h-80">
                </div>
            @endif
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
                <a href="/events?tab={{ $match->status === 'completed' ? 'results' : 'upcoming' }}"
                   class="inline-flex items-center gap-1.5 text-sm text-stone-500 hover:text-emerald-700 transition mb-5">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    Back to Events
                </a>

                <div class="flex flex-wrap items-center gap-2.5 mb-4">
                    <x-discipline-chip :discipline="$match->match_type" />
                    <x-level-chip :level="$match->series_level" :match-name="$match->name" />
                    @if($match->status === 'completed')
                        <x-status-chip status="completed" />
                    @else
                        <x-status-chip :status="$match->registration_status" />
                    @endif
                    @if($match->is_featured)
                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700 ring-1 ring-inset ring-amber-600/20">Featured</span>
                    @endif
                </div>

                <h1 class="font-heading text-3xl sm:text-4xl font-bold text-stone-900 tracking-tight mb-2">{{ $match->name }}</h1>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-stone-500">
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="size-4 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                        @if($match->isMultiDay())
                            {{-- Phase 4.1: explicit day-by-day header so a shooter can see
                                 both match-day boundaries without parsing a shorthand range. --}}
                            Day 1 {{ $match->match_date->format('l, j M') }}
                            <span class="text-stone-300">·</span>
                            Day 2 {{ $match->match_end_date->format('l, j M Y') }}
                        @else
                            {{ $match->match_date->format('l, j F Y') }}
                        @endif
                    </span>
                    @if($match->venue_name)
                        <span class="inline-flex items-center gap-1.5 text-stone-700 font-medium">
                            <svg class="size-4 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 0 1 15 0Z" /></svg>
                            {{ $match->venue_name }}
                        </span>
                    @endif
                    @if($match->location_display)
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="size-4 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" /></svg>
                            {{ $match->location_display }}
                        </span>
                    @endif
                    @if($match->director_name)
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="size-4 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                            MD: {{ $match->director_name }}
                            {{-- Phase 3.4: phone only when the MD has opted-in or the
                                 viewer is admin / owning MD. canShowDirectorContactTo()
                                 handles the entire policy; blade just renders. --}}
                            @if($match->canShowDirectorContactTo(auth()->user()))
                                <span class="text-stone-400">· {{ $match->match_director_contact }}</span>
                            @endif
                        </span>
                    @endif
                    @if($match->impact_match_id)
                        <a href="https://www.impactscoring.net/match/view?id={{ $match->impact_match_id }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 font-medium text-emerald-700 hover:text-emerald-800">
                            Impact match
                        </a>
                    @endif
                </div>
            </div>
        </div>

        {{-- Main Content --}}
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                {{-- ═══ Main Column ═══ --}}
                <div class="lg:col-span-2 space-y-6">
                    {{-- Description --}}
                    @if($match->description)
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                            <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-3">About This Event</h2>
                            <div class="prose prose-sm prose-stone max-w-none">
                                {!! nl2br(e($match->description)) !!}
                            </div>
                        </div>
                    @endif

                    {{-- Match Details --}}
                    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-4">Match Details</h2>
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
                            <div>
                                <dt class="text-xs text-stone-400">Date</dt>
                                <dd class="mt-0.5 text-sm font-medium text-stone-900">{{ $match->match_date->format('l, j F Y') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-stone-400">Discipline</dt>
                                <dd class="mt-1"><x-discipline-chip :discipline="$match->match_type" /></dd>
                            </div>
                            @if($match->venue_name)
                                <div>
                                    <dt class="text-xs text-stone-400">Venue</dt>
                                    <dd class="mt-0.5 text-sm font-medium text-stone-900">{{ $match->venue_name }}</dd>
                                </div>
                            @endif
                            @if($match->location_display)
                                <div>
                                    <dt class="text-xs text-stone-400">Location</dt>
                                    <dd class="mt-0.5 text-sm text-stone-700">{{ $match->location_display }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-xs text-stone-400">Match Level</dt>
                                <dd class="mt-1"><x-level-chip :level="$match->series_level" /></dd>
                            </div>
                            @if($match->director_name)
                                <div>
                                    <dt class="text-xs text-stone-400">Match Director</dt>
                                    <dd class="mt-0.5 text-sm text-stone-700">{{ $match->director_name }}</dd>
                                    {{-- Phase 3.4: same visibility policy as the hero. --}}
                                    @if($match->canShowDirectorContactTo(auth()->user()))
                                        <dd class="text-xs text-stone-400">{{ $match->match_director_contact }}</dd>
                                    @endif
                                </div>
                            @endif
                            @if($match->season)
                                <div>
                                    <dt class="text-xs text-stone-400">Season</dt>
                                    <dd class="mt-0.5 text-sm text-stone-700">{{ $match->season }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    {{-- Phase 3.3: optional venue details card. Only renders when
                         the MD has written something — a null column means no card. --}}
                    @if(filled($match->venue_details))
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                            <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-3">Venue Details</h2>
                            <div class="prose prose-sm prose-stone max-w-none">
                                {!! nl2br(e($match->venue_details)) !!}
                            </div>
                        </div>
                    @endif

                    {{-- Phase 3.3: schedule card. Rendered as a time-labelled list. --}}
                    @php $scheduleItems = $match->schedule_items; @endphp
                    @if(! empty($scheduleItems))
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                            <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-3">Schedule</h2>
                            <ol class="space-y-2 text-sm">
                                @foreach($scheduleItems as $item)
                                    <li class="flex items-baseline gap-3">
                                        <span class="min-w-[60px] font-mono text-xs text-emerald-700 font-semibold tabular-nums">{{ $item['time'] ?: '—' }}</span>
                                        <span class="text-stone-700">{{ $item['label'] }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endif

                    {{-- Phase 3.3: squadding card. Each squad is its own small block. --}}
                    @php $squaddingItems = $match->squadding_items; @endphp
                    @if(! empty($squaddingItems))
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                            <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-4">Squadding</h2>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                @foreach($squaddingItems as $squad)
                                    <div class="rounded-xl border border-stone-100 bg-stone-50/60 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-wider text-stone-500 mb-2">
                                            {{ $squad['squad_name'] ?: 'Squad' }}
                                            <span class="text-stone-400 font-normal">· {{ count($squad['members']) }}</span>
                                        </p>
                                        @if(! empty($squad['members']))
                                            <ol class="space-y-1 text-sm text-stone-700">
                                                @foreach($squad['members'] as $member)
                                                    <li class="flex items-baseline gap-2">
                                                        <span class="text-xs text-stone-400 tabular-nums w-5 text-right">{{ $loop->iteration }}.</span>
                                                        <span>{{ $member }}</span>
                                                    </li>
                                                @endforeach
                                            </ol>
                                        @else
                                            <p class="text-xs text-stone-400 italic">No members listed yet.</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Phase 3.3: attachments card — PDFs, course-of-fire docs, maps. --}}
                    @php $attachmentItems = $match->attachment_items; @endphp
                    @if(! empty($attachmentItems))
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                            <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-3">Attachments</h2>
                            <ul class="space-y-2">
                                @foreach($attachmentItems as $attach)
                                    <li>
                                        <a href="{{ $attach['url'] }}"
                                           target="_blank"
                                           rel="noopener"
                                           class="inline-flex items-center gap-2 text-sm font-medium text-emerald-700 hover:text-emerald-800">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                                            </svg>
                                            {{ $attach['label'] ?: $attach['url'] }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Results Sponsor Strip (above results) --}}
                    @if($match->scores->isNotEmpty())
                        <x-sponsors-strip placement="results_pages" class="bg-white border border-stone-200 rounded-2xl shadow-sm !py-4 mb-4" />
                    @endif

                    {{-- Phase 4.2: admin-only import attribution. Lists who uploaded
                         each CSV / Impact pull and when, so an auditor doesn't have
                         to open the workspace scores screen to find out. Collection
                         is empty for non-admins, so this block renders nothing. --}}
                    @if(($scoreImports ?? collect())->isNotEmpty())
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-5" x-data="{ open: false }">
                            <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-left" :aria-expanded="open.toString()">
                                <div>
                                    <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400">Import attribution <span class="font-normal text-stone-400">(admin)</span></h2>
                                    <p class="mt-0.5 text-xs text-stone-500" x-text="open ? 'Hide trail' : 'Show ' + {{ $scoreImports->count() }} + ' entry' + ({{ $scoreImports->count() }} === 1 ? '' : 'ies')">Show entries</p>
                                </div>
                                <svg class="size-4 text-stone-400 transition-transform" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            <div x-show="open" x-cloak class="mt-3">
                                <ul class="divide-y divide-stone-100 text-sm">
                                    @foreach($scoreImports as $import)
                                        <li class="flex items-start justify-between gap-3 py-2">
                                            <div class="min-w-0">
                                                <p class="font-medium text-stone-900">
                                                    {{ $import->original_filename ?: ucfirst(str_replace('_', ' ', (string) $import->source_type)) }}
                                                </p>
                                                <p class="text-xs text-stone-500">
                                                    By {{ $import->uploader?->name ?? 'system' }}
                                                    · {{ $import->created_at->format('j M Y, H:i') }}
                                                    @if($import->day)
                                                        · Day {{ $import->day }}
                                                    @endif
                                                </p>
                                            </div>
                                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider ring-1 ring-inset
                                                @switch($import->import_status)
                                                    @case('complete') bg-emerald-50 text-emerald-700 ring-emerald-600/20 @break
                                                    @case('failed')   bg-rose-50 text-rose-700 ring-rose-600/20 @break
                                                    @default          bg-stone-100 text-stone-600 ring-stone-500/20
                                                @endswitch
                                            ">{{ $import->import_status ?: 'pending' }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    {{-- Results Table (if completed) --}}
                    @if($match->scores->isNotEmpty())
                        @php
                            // Phase 4.4: precompute division-leader raws so each row can
                            // show its own-class percentage (score / division leader × 100)
                            // without a client-side second pass over the full set.
                            $divisionLeaderRaw = $match->scores
                                ->filter(fn ($s) => $s->division_id !== null && $s->raw_score !== null)
                                ->groupBy('division_id')
                                ->map(fn ($rows) => (float) $rows->max('raw_score'));

                            $overallLeaderRaw = (float) ($match->scores->max('raw_score') ?: 0.0);
                        @endphp
                        @php
                            $scoreData = $match->scores->sortBy('overall_rank')->map(function ($score) use ($viewerCanRemoveZeroScores, $saBadgesByUser, $match, $divisionLeaderRaw, $overallLeaderRaw) {
                                // Phase 4.8: canonical profile URL per shooter. Prefer the
                                // SAPRF-number-keyed route when the user carries one; otherwise
                                // fall back to the legacy user-id route that the controller
                                // still accepts. Guest / unlinked scores have no destination.
                                $saprfNumber = $score->user?->membership?->saprf_number;
                                $profileUrl = null;
                                if ($score->user_id !== null) {
                                    $profileUrl = $saprfNumber
                                        ? route('shooters.show', ['saprfNumber' => $saprfNumber])
                                        : route('standings.shooter', ['season' => $match->season ?? now()->year, 'user' => $score->user_id]);
                                }
                                $saBadge = $saBadgesByUser[$score->user_id] ?? null;
                                return [
                                    'id' => $score->id,
                                    'name' => $score->shooter_name,
                                    'raw' => (float) $score->raw_score,
                                    // Phase 2.3: pre-format the display value server-side so the
                                    // integer PRS / PR22 rule lives in one helper, and the Alpine
                                    // row stays dumb (no JS format table of discipline codes).
                                    'raw_display' => \App\Support\ScoreFormat::format($score->raw_score, $match->match_type, $match->series),
                                    'norm' => $score->normalized_score ? round((float) $score->normalized_score, 2) : null,
                                    'div_norm' => $score->division_normalized_score ? round((float) $score->division_normalized_score, 2) : null,
                                    // Phase 4.4: percentage of the match winner (overall %) and of
                                    // the division winner (div %). Guard against div-by-zero when
                                    // a match is still being imported and the leader is zero.
                                    'match_pct' => $overallLeaderRaw > 0 && $score->raw_score !== null
                                        ? round(((float) $score->raw_score) / $overallLeaderRaw * 100, 2)
                                        : null,
                                    'div_pct' => $score->division_id !== null
                                        && isset($divisionLeaderRaw[$score->division_id])
                                        && $divisionLeaderRaw[$score->division_id] > 0
                                        && $score->raw_score !== null
                                        ? round(((float) $score->raw_score) / $divisionLeaderRaw[$score->division_id] * 100, 2)
                                        : null,
                                    'overall_rank' => $score->overall_rank,
                                    'div_rank' => $score->division_rank,
                                    // Phase 1.4: carry the persisted tie flags so the Alpine row
                                    // can render "T2" without re-grouping the whole set client-side.
                                    'is_tied_overall' => (bool) $score->is_tied_overall,
                                    'is_tied_division' => (bool) $score->is_tied_division,
                                    'div_id' => $score->division_id,
                                    'div_name' => $score->division?->name ?? '—',
                                    'status' => $score->status,
                                    // Phase 4.8: resolved profile link (null = no profile).
                                    'profile_url' => $profileUrl,
                                    // Only zero-score rows are eligible for inline removal, and only when
                                    // the current viewer is an admin or the MD who owns this match.
                                    'can_remove' => $viewerCanRemoveZeroScores && (float) $score->raw_score === 0.0,
                                    'badge' => match ($score->status) {
                                        'non_member' => ['label' => 'Non-member', 'class' => 'bg-stone-100 text-stone-600 ring-stone-200', 'tip' => 'This shooter was not a SAPRF member on the match date — their score is displayed but does not contribute to season standings.'],
                                        'lapsed'     => ['label' => 'Lapsed',     'class' => 'bg-rose-50 text-rose-700 ring-rose-200', 'tip' => 'This shooter had a lapsed membership on the match date — their score is displayed but does not contribute to season standings.'],
                                        'pending'    => ['label' => 'Pending',    'class' => 'bg-amber-50 text-amber-700 ring-amber-200', 'tip' => 'The match director has not yet confirmed this score. It will update when verified.'],
                                        default      => null,
                                    },
                                    // Phase 4.5: per-stage breakdown parsed from the raw Impact
                                    // payload. Keeps the Alpine row dumb — null means "no stage
                                    // detail available", a list of {label, points} drives the
                                    // expand/collapse accordion inside the row.
                                    'stages' => ($orderedStages = \App\Support\ImpactStages::forDisplay($score->raw_meta['impact']['stages'] ?? null)) === []
                                        ? null
                                        : $orderedStages,
                                    // "SA 2026" chip data for the Alpine row — null means render nothing.
                                    'sa_years' => $saBadge['years'] ?? [],
                                    'sa_tooltip' => $saBadge['tooltip'] ?? null,
                                ];
                            })->values()->toArray();

                            $divList = $divisions->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->toArray();
                        @endphp

                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden"
                             x-data="matchResults({{ Js::from($scoreData) }}, {{ Js::from($divList) }})">
                            @if($viewerCanRemoveZeroScores)
                                {{-- Shared form for the inline "remove zero-score shooter" action. The
                                     click handler sets the action URL and submits; only rows where the
                                     viewer is authorised AND raw_score = 0 render the trigger button. --}}
                                <form x-ref="removeZeroForm" method="POST" class="hidden">
                                    @csrf
                                    @method('DELETE')
                                </form>
                            @endif
                            @php
                                // Phase 1.4: show the "T = tied placement" legend only when there
                                // is actually a tie in this match, so the normal case stays uncluttered.
                                $hasAnyTie = $match->scores->contains(fn ($s) => $s->is_tied_overall || $s->is_tied_division);
                            @endphp
                            <div class="px-6 py-4 border-b border-stone-100">
                                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                                    <h2 class="text-lg font-semibold text-stone-900">Results</h2>
                                    <div class="flex items-center gap-3">
                                        @if($hasAnyTie)
                                            <span class="text-xs text-stone-500" title="Shooters marked T share the same placement after the match's tiebreak rule was applied.">
                                                <span class="font-semibold">T</span> = tied placement
                                            </span>
                                        @endif
                                        <span class="text-xs text-stone-400">{{ $match->scores->count() }} {{ Str::plural('shooter', $match->scores->count()) }}</span>
                                        {{-- Score downloads are MD + staff only. The matching gate on
                                             the controller side (abortIfNotAllowedToDownloadResults)
                                             returns 404 for everyone else, so even a direct URL leak
                                             doesn't hand the file over. Rank-and-file members and
                                             anonymous visitors never see these buttons at all. --}}
                                        @if($viewerCanDownloadResults ?? false)
                                            <div class="flex items-center gap-1.5">
                                                <a href="{{ route('events.results.csv', $match) }}"
                                                   class="inline-flex items-center gap-1 rounded-md border border-stone-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-stone-600 hover:text-stone-900 hover:border-stone-300 transition"
                                                   title="Download these results as CSV (MD / admin only)">
                                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                                                    CSV
                                                </a>
                                                <a href="{{ route('events.results.pdf', $match) }}"
                                                   class="inline-flex items-center gap-1 rounded-md border border-stone-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-stone-600 hover:text-stone-900 hover:border-stone-300 transition"
                                                   title="Download these results as PDF (MD / admin only)">
                                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m5.56-.5c.42-.42 1-.65 1.59-.65h.44c1.24 0 2.25 1.01 2.25 2.25" /></svg>
                                                    PDF
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                @if(! $match->scoresAreFinalized() && ($match->impact_live_sync || $match->impact_live_synced_at))
                                    <p class="mb-3 text-xs text-amber-900 bg-amber-50 border border-amber-200 rounded-md px-2.5 py-1.5">
                                        Incomplete — unverified. These scores are a live pull from Impact and do not count until the match director confirms them.
                                    </p>
                                @endif
                                @if($match->everyone_counts)
                                    <p class="mb-3 text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-md px-2.5 py-1.5 inline-block">
                                        All shooters count for this match regardless of membership status on the day.
                                    </p>
                                @endif
                                {{-- Division filter — purely a visual filter, not a re-ranking.
                                     Rank numbers and Match % stay anchored to the overall board
                                     so a lone Factory shooter never gets a "1st in Factory at
                                     100 %" podium. Default pill ("All divisions") = Overall.
                                     The chip beside each shooter's name shows which division
                                     they shot, so the overall list stays legible when the
                                     filter is off. --}}
                                @if(count($divList) > 1)
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400 mr-1">Division</span>
                                        <button type="button"
                                                @click="divFilter = null"
                                                :class="divFilter === null ? 'bg-stone-900 text-white border-stone-900' : 'bg-white text-stone-600 border-stone-200 hover:border-stone-300'"
                                                class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold transition">All divisions</button>
                                        <template x-for="d in divs" :key="d.id">
                                            <button type="button"
                                                    @click="divFilter = d.id"
                                                    :class="divFilter === d.id ? 'bg-stone-900 text-white border-stone-900' : 'bg-white text-stone-600 border-stone-200 hover:border-stone-300'"
                                                    class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold transition"
                                                    x-text="d.name"></button>
                                        </template>
                                    </div>
                                @endif
                            </div>

                            <div class="overflow-x-auto">
                                <table class="min-w-full">
                                    <thead class="border-b border-stone-200 bg-stone-50">
                                        <tr>
                                            <th class="px-5 py-3 text-center text-[11px] font-bold uppercase tracking-wider text-stone-400 w-14">#</th>
                                            <th class="px-5 py-3 text-left text-[11px] font-bold uppercase tracking-wider text-stone-400">Shooter</th>
                                            {{-- "Score" works for every discipline — PRS/PR22 integer impacts
                                                 or the formatted precision score, both go through ScoreFormat. --}}
                                            <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-stone-400">Score</th>
                                            <th class="px-5 py-3 text-right text-[11px] font-bold uppercase tracking-wider text-stone-400" title="Score as a percentage of the match winner's score — the standard match-% metric.">Match %</th>
                                        </tr>
                                    </thead>
                                    {{-- Each shooter owns its own <tbody> so the main row and (optional)
                                         stage-breakdown row sit together in the DOM. Multiple <tbody>s per
                                         <table> are valid HTML, and this is the one way to interleave an
                                         Alpine x-for with a conditional sibling without re-rendering a
                                         separate x-for AFTER all main rows — which was the old bug: a
                                         click on the top shooter revealed stages 70 rows further down,
                                         well off-screen, so the chevron looked dead. --}}
                                    <template x-for="row in filteredRows" :key="row.id">
                                        <tbody>
                                            <tr class="border-b border-stone-50 hover:bg-stone-50/50"
                                                :class="{
                                                    'bg-amber-50/50': row._rank === 1,
                                                    'bg-stone-50/50': row._rank === 2,
                                                    'bg-orange-50/30': row._rank === 3,
                                                }">
                                                                <td class="px-5 py-3 text-center">
                                                    <template x-if="row._rank <= 3">
                                                        <span class="inline-flex items-center justify-center size-7 rounded-full text-sm font-bold"
                                                              :class="{
                                                                  'bg-amber-100 text-amber-700': row._rank === 1,
                                                                  'bg-stone-200 text-stone-600': row._rank === 2,
                                                                  'bg-amber-50 text-amber-600': row._rank === 3,
                                                              }" x-text="row._rank_label"></span>
                                                    </template>
                                                    <template x-if="row._rank > 3">
                                                        <span class="text-sm text-stone-400" x-text="row._rank_label"></span>
                                                    </template>
                                                </td>
                                                <td class="px-5 py-3 text-sm font-medium text-stone-900">
                                                    {{-- Phase 4.8: name is a link when the shooter has a profile, plain text otherwise. --}}
                                                    <template x-if="row.profile_url">
                                                        <a :href="row.profile_url" class="hover:text-emerald-700 hover:underline underline-offset-2 transition" x-text="row.name"></a>
                                                    </template>
                                                    <template x-if="! row.profile_url">
                                                        <span x-text="row.name"></span>
                                                    </template>
                                                    {{-- Division chip — purely a label, not a ranking bucket. Rendered
                                                         when the score carries a division so the overall list stays
                                                         scannable; hidden for unassigned/guest rows. --}}
                                                    <template x-if="row.div_id">
                                                        <span class="ml-2 inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 ring-1 ring-inset ring-amber-200"
                                                              x-text="row.div_name"></span>
                                                    </template>
                                                    <template x-if="row.sa_years && row.sa_years.length">
                                                        <span class="ml-2 inline-flex items-center gap-1" :title="row.sa_tooltip || ('Represented South Africa in ' + row.sa_years.join(', '))" :aria-label="'Represented South Africa in ' + row.sa_years.join(', ')">
                                                            <template x-for="(y, idx) in row.sa_years.slice(0, 2)" :key="y">
                                                                <span class="inline-flex items-center gap-1 rounded-md bg-gradient-to-br from-emerald-50 to-amber-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 ring-1 ring-inset ring-emerald-200">
                                                                    <svg class="size-2.5 text-emerald-600" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                                        <path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/>
                                                                    </svg>
                                                                    <span>SA <span x-text="y"></span></span>
                                                                </span>
                                                            </template>
                                                            <template x-if="row.sa_years.length > 2">
                                                                <span class="inline-flex items-center rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200" x-text="'+' + (row.sa_years.length - 2)"></span>
                                                            </template>
                                                        </span>
                                                    </template>
                                                    <template x-if="row.badge">
                                                        {{-- Phase 4.6: badge now carries a tooltip explaining what the status means. --}}
                                                        <span class="ml-2 inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset uppercase tracking-wide cursor-help"
                                                              :class="row.badge.class"
                                                              :title="row.badge.tip || row.badge.label"
                                                              x-text="row.badge.label"></span>
                                                    </template>
                                                    {{-- Phase 4.5: tiny chevron next to the name expands the stage breakdown. --}}
                                                    <template x-if="row.stages && row.stages.length">
                                                        <button type="button"
                                                                @click.stop="toggleStages(row.id)"
                                                                class="ml-2 inline-flex items-center text-stone-400 hover:text-stone-600 transition"
                                                                :aria-expanded="(expandedStages[row.id] === true).toString()"
                                                                :aria-label="(expandedStages[row.id] === true ? 'Hide ' : 'Show ') + 'stage breakdown'">
                                                            <svg class="size-3.5 transition-transform" :class="{ 'rotate-180': expandedStages[row.id] === true }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                                        </button>
                                                    </template>
                                                    @if($viewerCanRemoveZeroScores)
                                                        <template x-if="row.can_remove">
                                                            <button type="button"
                                                                    @click.stop.prevent="removeZeroScore(row)"
                                                                    title="This shooter didn't participate — remove them from the results"
                                                                    class="ml-2 align-middle text-stone-400 hover:text-red-600 transition">
                                                                <svg class="size-4 inline" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                                </svg>
                                                                <span class="sr-only">Remove zero-score shooter</span>
                                                            </button>
                                                        </template>
                                                    @endif
                                                </td>
                                                <td class="px-5 py-3 text-sm text-stone-700 text-right tabular-nums" x-text="row.raw_display"></td>
                                                <td class="px-5 py-3 text-sm font-semibold text-emerald-700 text-right tabular-nums" x-text="row.match_pct !== null ? row.match_pct.toFixed(2) + '%' : '—'"></td>
                                            </tr>
                                            {{-- Stage breakdown row — sibling of the main row inside the
                                                 same per-shooter <tbody>, toggled by the chevron on the
                                                 shooter cell. Only renders when stages exist AND the
                                                 shooter is currently expanded. --}}
                                            <template x-if="row.stages && row.stages.length && expandedStages[row.id] === true">
                                                <tr class="bg-stone-50/60 border-b border-stone-100">
                                                    <td colspan="4" class="px-10 py-3">
                                                        <p class="text-[10px] font-bold uppercase tracking-wider text-stone-400 mb-2">Stages for <span x-text="row.name"></span></p>
                                                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                                                            <template x-for="(st, i) in row.stages" :key="row.id + '-' + i">
                                                                <div class="flex items-center justify-between rounded-md bg-white px-2.5 py-1.5 ring-1 ring-stone-100 text-xs">
                                                                    <span class="text-stone-500 truncate" x-text="st.label"></span>
                                                                    <span class="font-semibold text-stone-800 tabular-nums ml-2" x-text="st.points !== null ? st.points : '—'"></span>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </template>
                                    {{-- Empty state gets its own tbody too so the x-for above can own a
                                         single root per shooter without competing for the parent slot. --}}
                                    <template x-if="filteredRows.length === 0">
                                        <tbody>
                                            <tr>
                                                <td colspan="4" class="px-5 py-12 text-center text-sm text-stone-400">No results.</td>
                                            </tr>
                                        </tbody>
                                    </template>
                                </table>
                            </div>
                        </div>

                        <script>
                        document.addEventListener('alpine:init', () => {
                            // Results table ranks everyone on the single Overall board. The
                            // division pills above the table are a *visual filter* only — picking
                            // a division narrows the list to shooters in that cohort, but their
                            // rank number and Match % stay the overall values (so a lone Factory
                            // shooter never becomes "1st in Factory at 100 %"). That's why there
                            // is no Div % column: it would compute per-division leaders and
                            // encourage misleading podiums.
                            Alpine.data('matchResults', (scores, divs) => ({
                                scores,
                                divs,
                                divFilter: null,
                                expandedStages: {},

                                toggleStages(id) {
                                    this.expandedStages[id] = this.expandedStages[id] !== true;
                                },
                                @if($viewerCanRemoveZeroScores)

                                removeZeroScore(row) {
                                    if (! confirm(`Remove ${row.name} from the results? Their score row will be deleted and standings recalculated.`)) return;
                                    const form = this.$refs.removeZeroForm;
                                    if (! form) return;
                                    form.action = `/scores/${row.id}/remove-zero`;
                                    form.submit();
                                },
                                @endif

                                get filteredRows() {
                                    // Competition ranking: tied rows render with a leading "T"
                                    // (e.g. T2, T2, 4). The persisted is_tied_overall flag is
                                    // authoritative — the backend already decided the ties.
                                    // Division filter is applied *after* the overall rank is
                                    // attached so filtered rows keep their original overall
                                    // placement (e.g. filtering to Open might show #1, #4, #9).
                                    return this.scores
                                        .filter(s => this.divFilter === null || s.div_id === this.divFilter)
                                        .map(s => ({
                                            ...s,
                                            _norm: s.norm,
                                            _rank: s.overall_rank,
                                            _rank_label: s.overall_rank === null
                                                ? '—'
                                                : (s.is_tied_overall ? 'T' : '') + s.overall_rank,
                                        }))
                                        .sort((a, b) => (a._rank ?? 999) - (b._rank ?? 999));
                                },
                            }));
                        });
                        </script>
                    @elseif($match->status !== 'completed')
                        {{-- No results yet for upcoming events --}}
                    @else
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-12 text-center">
                            <div class="inline-flex items-center justify-center size-16 rounded-2xl bg-stone-100 mb-4">
                                <svg class="size-8 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                            </div>
                            <h3 class="text-lg font-semibold text-stone-700">No results yet</h3>
                            <p class="mt-1 text-sm text-stone-400">Results will be published after the match is completed.</p>
                        </div>
                    @endif

                    {{-- Entry List — hidden entirely for imported historic events (scores exist but no registration data). --}}
                    @if(! ($match->status === 'completed' && $match->scores->isNotEmpty() && $entries->isEmpty()))
                    @php
                        // Reconciliation is only meaningful once the match is
                        // completed and results are up. Before that, "entered"
                        // is the whole story.
                        $showReconciliation = $match->status === 'completed' && $match->scores->isNotEmpty();
                    @endphp
                    {{-- Entry list is always collapsed by default; click the header row to expand. --}}
                    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden"
                         x-data="{ open: false }">
                        <button type="button"
                                @click="open = !open"
                                class="w-full flex items-center justify-between gap-3 px-6 py-4 border-b border-stone-100 hover:bg-stone-50/60 transition text-left"
                                :aria-expanded="open.toString()"
                                aria-controls="entry-list-panel">
                            <div class="flex items-center gap-3">
                                <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400">Entry List</h2>
                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600">
                                    {{ $entries->count() }} {{ Str::plural('shooter', $entries->count()) }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3">
                                @if($showReconciliation)
                                    {{-- Compact reconciliation chip: scored / no-shows / walk-ins. Walk-ins column stays hidden until any exist. --}}
                                    <div class="hidden sm:flex items-center gap-1.5 text-[11px] font-medium">
                                        <span class="inline-flex items-center rounded-md bg-emerald-50 px-1.5 py-0.5 text-emerald-700 ring-1 ring-inset ring-emerald-200" title="Registered shooters whose scores were captured">
                                            {{ $reconciliation['scored'] }} scored
                                        </span>
                                        @if($reconciliation['no_shows'] > 0)
                                            <span class="inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-amber-700 ring-1 ring-inset ring-amber-200" title="Registered but no score captured">
                                                {{ $reconciliation['no_shows'] }} no-show{{ $reconciliation['no_shows'] === 1 ? '' : 's' }}
                                            </span>
                                        @endif
                                        @if($reconciliation['walk_ins'] > 0)
                                            <span class="inline-flex items-center rounded-md bg-sky-50 px-1.5 py-0.5 text-sky-700 ring-1 ring-inset ring-sky-200" title="Shot but not on the entry list — MD confirmed walk-ins">
                                                {{ $reconciliation['walk_ins'] }} walk-in{{ $reconciliation['walk_ins'] === 1 ? '' : 's' }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                                <svg class="size-4 text-stone-400 transition-transform" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </div>
                        </button>

                        <div id="entry-list-panel" x-show="open" x-cloak>
                        @if($entries->isEmpty())
                            <div class="px-6 py-12 text-center">
                                <p class="text-sm text-stone-400">No shooters have registered yet.</p>
                            </div>
                        @else
                            @php
                                $entryRows = $entries->map(function ($entry) use ($saBadgesByUser) {
                                    $status = $entry->registration_status;
                                    $saBadge = $saBadgesByUser[$entry->user_id] ?? null;

                                    return [
                                        'id' => $entry->id,
                                        'name' => $entry->user?->name ?? $entry->shooter_name,
                                        'division' => $entry->division?->name ?? '',
                                        'division_order' => $entry->division?->display_order ?? 999,
                                        'province' => $entry->user?->province?->name ?? '',
                                        'status' => $status,
                                        'status_label' => match ($status) {
                                            'confirmed' => 'Confirmed',
                                            'waitlisted' => 'Waitlisted',
                                            default => 'Pending',
                                        },
                                        'status_class' => match ($status) {
                                            'confirmed' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
                                            'waitlisted' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
                                            default => 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                        },
                                        'status_order' => match ($status) {
                                            'confirmed' => 1,
                                            'waitlisted' => 2,
                                            default => 3,
                                        },
                                        'sa_years' => $saBadge['years'] ?? [],
                                        'sa_tooltip' => $saBadge['tooltip'] ?? null,
                                    ];
                                })->values()->all();
                            @endphp
                            <div x-data="entryList({{ Js::from($entryRows) }})">
                                {{-- Phase 3.1: view toggle. Grouped-by-division is the
                                     default (every shooter under its division heading);
                                     Flat falls back to the pre-overhaul sortable table
                                     for people who want to see everyone in one list. --}}
                                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 border-b border-stone-100 bg-stone-50/40">
                                    <div class="flex items-center gap-2">
                                        <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">View</span>
                                        <div class="flex rounded-lg bg-stone-100 p-0.5 w-fit">
                                            <button type="button" @click="mode = 'grouped'" :class="mode === 'grouped' ? 'bg-white shadow-sm text-stone-900' : 'text-stone-500 hover:text-stone-700'" class="px-3 py-1 rounded-md text-xs font-semibold transition" :aria-pressed="(mode === 'grouped').toString()">Grouped</button>
                                            <button type="button" @click="mode = 'flat'" :class="mode === 'flat' ? 'bg-white shadow-sm text-stone-900' : 'text-stone-500 hover:text-stone-700'" class="px-3 py-1 rounded-md text-xs font-semibold transition" :aria-pressed="(mode === 'flat').toString()">Flat</button>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2" x-show="mode === 'grouped'">
                                        <button type="button" @click="toggleAllGroups" class="text-xs font-semibold text-stone-500 hover:text-stone-700 transition">
                                            <span x-text="allGroupsOpen ? 'Collapse all' : 'Expand all'"></span>
                                        </button>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2" x-show="mode === 'flat'">
                                        <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Sort by</span>
                                        <div class="flex flex-wrap rounded-lg bg-stone-100 p-0.5 w-fit">
                                            <button type="button" @click="setSort('shooter')" :class="sort === 'shooter' ? 'bg-white shadow-sm text-stone-900' : 'text-stone-500 hover:text-stone-700'" class="px-3 py-1 rounded-md text-xs font-semibold transition" :aria-pressed="(sort === 'shooter').toString()">Shooter</button>
                                            <button type="button" @click="setSort('province')" :class="sort === 'province' ? 'bg-white shadow-sm text-stone-900' : 'text-stone-500 hover:text-stone-700'" class="px-3 py-1 rounded-md text-xs font-semibold transition" :aria-pressed="(sort === 'province').toString()">Province</button>
                                            <button type="button" @click="setSort('status')" :class="sort === 'status' ? 'bg-white shadow-sm text-stone-900' : 'text-stone-500 hover:text-stone-700'" class="px-3 py-1 rounded-md text-xs font-semibold transition" :aria-pressed="(sort === 'status').toString()">Status</button>
                                        </div>
                                    </div>
                                </div>

                                {{-- Grouped view: one collapsible block per division, each with a title and count. --}}
                                <div x-show="mode === 'grouped'" x-cloak class="divide-y divide-stone-100">
                                    <template x-for="group in groupedEntries" :key="group.key">
                                        <div>
                                            <button type="button"
                                                    @click="toggleGroup(group.key)"
                                                    class="w-full flex items-center justify-between gap-3 px-6 py-3 bg-stone-50/50 hover:bg-stone-100/60 transition text-left"
                                                    :aria-expanded="(groupOpen[group.key] !== false).toString()">
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200" x-text="group.name"></span>
                                                    <span class="text-xs text-stone-500" x-text="group.rows.length + ' ' + (group.rows.length === 1 ? 'shooter' : 'shooters')"></span>
                                                </div>
                                                <svg class="size-4 text-stone-400 transition-transform" :class="{ 'rotate-180': groupOpen[group.key] !== false }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                            </button>
                                            <div x-show="groupOpen[group.key] !== false" x-cloak>
                                                <table class="min-w-full">
                                                    <tbody class="divide-y divide-stone-100">
                                                        <template x-for="(entry, i) in group.rows" :key="entry.id">
                                                            <tr class="hover:bg-stone-50/60 transition-colors">
                                                                <td class="px-6 py-2 text-sm text-stone-400 tabular-nums w-10" x-text="i + 1"></td>
                                                                <td class="px-6 py-2 text-sm font-medium text-stone-900">
                                                                    <span x-text="entry.name"></span>
                                                                    <template x-if="entry.sa_years && entry.sa_years.length">
                                                                        <span class="ml-2 inline-flex items-center gap-1" :title="entry.sa_tooltip || ('Represented South Africa in ' + entry.sa_years.join(', '))" :aria-label="'Represented South Africa in ' + entry.sa_years.join(', ')">
                                                                            <template x-for="(y, idx) in entry.sa_years.slice(0, 2)" :key="y">
                                                                                <span class="inline-flex items-center gap-1 rounded-md bg-gradient-to-br from-emerald-50 to-amber-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 ring-1 ring-inset ring-emerald-200">
                                                                                    <span>SA <span x-text="y"></span></span>
                                                                                </span>
                                                                            </template>
                                                                        </span>
                                                                    </template>
                                                                </td>
                                                                <td class="px-6 py-2 text-sm text-stone-500" x-text="entry.province || '—'"></td>
                                                                <td class="px-6 py-2">
                                                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset" :class="entry.status_class" x-text="entry.status_label"></span>
                                                                </td>
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                {{-- Flat view: the old sortable table, kept for people who like it. --}}
                                <div x-show="mode === 'flat'" x-cloak class="overflow-x-auto">
                                    <table class="min-w-full">
                                        <thead>
                                            <tr class="border-b border-stone-100 bg-stone-50/60">
                                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-400 w-10">#</th>
                                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-400">Shooter</th>
                                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-400">Division</th>
                                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-400">Province</th>
                                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-400">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-stone-100">
                                            <template x-for="(entry, i) in sortedEntries" :key="entry.id">
                                                <tr class="hover:bg-stone-50 transition-colors">
                                                    <td class="px-6 py-3 text-sm text-stone-400 tabular-nums" x-text="i + 1"></td>
                                                    <td class="px-6 py-3 text-sm font-medium text-stone-900">
                                                        <span x-text="entry.name"></span>
                                                        <template x-if="entry.sa_years && entry.sa_years.length">
                                                            <span class="ml-2 inline-flex items-center gap-1" :title="entry.sa_tooltip || ('Represented South Africa in ' + entry.sa_years.join(', '))" :aria-label="'Represented South Africa in ' + entry.sa_years.join(', ')">
                                                                <template x-for="(y, idx) in entry.sa_years.slice(0, 2)" :key="y">
                                                                    <span class="inline-flex items-center gap-1 rounded-md bg-gradient-to-br from-emerald-50 to-amber-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 ring-1 ring-inset ring-emerald-200">
                                                                        <svg class="size-2.5 text-emerald-600" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                                            <path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/>
                                                                        </svg>
                                                                        <span>SA <span x-text="y"></span></span>
                                                                    </span>
                                                                </template>
                                                                <template x-if="entry.sa_years.length > 2">
                                                                    <span class="inline-flex items-center rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200" x-text="'+' + (entry.sa_years.length - 2)"></span>
                                                                </template>
                                                            </span>
                                                        </template>
                                                    </td>
                                                    <td class="px-6 py-3 text-sm">
                                                        <template x-if="entry.division">
                                                            <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200" x-text="entry.division"></span>
                                                        </template>
                                                        <template x-if="!entry.division">
                                                            <span class="text-stone-400">—</span>
                                                        </template>
                                                    </td>
                                                    <td class="px-6 py-3 text-sm text-stone-500" x-text="entry.province || '—'"></td>
                                                    <td class="px-6 py-3">
                                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset" :class="entry.status_class" x-text="entry.status_label"></span>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <script>
                            document.addEventListener('alpine:init', () => {
                                Alpine.data('entryList', (entries) => ({
                                    entries,
                                    mode: 'grouped',
                                    sort: 'shooter',
                                    groupOpen: {},

                                    setSort(key) {
                                        this.sort = key;
                                    },

                                    toggleGroup(key) {
                                        // undefined ⇒ open (default), explicit false ⇒ closed.
                                        this.groupOpen[key] = this.groupOpen[key] === false;
                                    },

                                    toggleAllGroups() {
                                        const target = ! this.allGroupsOpen;
                                        for (const g of this.groupedEntries) {
                                            this.groupOpen[g.key] = target;
                                        }
                                    },

                                    get allGroupsOpen() {
                                        return this.groupedEntries.every(g => this.groupOpen[g.key] !== false);
                                    },

                                    get sortedEntries() {
                                        const rows = [...this.entries];
                                        const byName = (a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });

                                        rows.sort((a, b) => {
                                            if (this.sort === 'shooter') {
                                                return byName(a, b);
                                            }
                                            if (this.sort === 'province') {
                                                if (! a.province && b.province) return 1;
                                                if (a.province && ! b.province) return -1;
                                                return (a.province || '').localeCompare(b.province || '', undefined, { sensitivity: 'base' }) || byName(a, b);
                                            }
                                            if (this.sort === 'status') {
                                                return (a.status_order - b.status_order) || byName(a, b);
                                            }
                                            return (a.division_order - b.division_order) || byName(a, b);
                                        });

                                        return rows;
                                    },

                                    get groupedEntries() {
                                        // Build an ordered Map keyed by division display name.
                                        const groups = new Map();
                                        for (const row of this.entries) {
                                            const key = row.division || '__none__';
                                            if (! groups.has(key)) {
                                                groups.set(key, {
                                                    key,
                                                    name: row.division || 'No division',
                                                    order: row.division_order,
                                                    rows: [],
                                                });
                                            }
                                            groups.get(key).rows.push(row);
                                        }
                                        const list = [...groups.values()];
                                        list.sort((a, b) => (a.order - b.order));
                                        for (const g of list) {
                                            g.rows.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
                                        }
                                        return list;
                                    },
                                }));
                            });
                            </script>
                        @endif
                        </div>{{-- /#entry-list-panel --}}
                    </div>
                    @endif
                </div>

                {{-- ═══ Sidebar ═══ --}}
                <div class="space-y-6">
                    {{-- Registration Card --}}
                    @if($match->status !== 'completed')
                        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                            <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-4">Registration</h2>

                            {{-- Status indicator --}}
                            <div class="mb-4">
                                <x-status-chip :status="$match->registration_status" />
                            </div>

                            <dl class="space-y-3 mb-5">
                                @if($match->registration_open_date)
                                    <div class="flex items-center justify-between">
                                        <dt class="text-xs text-stone-400">Opens</dt>
                                        <dd class="text-sm font-medium text-stone-700">{{ $match->registration_open_date->format('j M Y') }}</dd>
                                    </div>
                                @endif
                                @if($match->registration_close_date)
                                    <div class="flex items-center justify-between">
                                        <dt class="text-xs text-stone-400">Closes</dt>
                                        <dd class="text-sm font-medium {{ $match->registration_close_date->isFuture() && $match->registration_close_date->diffInDays(now()) <= 2 ? 'text-red-600' : 'text-stone-700' }}">
                                            {{ $match->registration_close_date->format('j M Y') }}
                                        </dd>
                                    </div>
                                @endif
                                <div class="flex items-center justify-between">
                                    <dt class="text-xs text-stone-400">Member Fee</dt>
                                    <dd class="text-sm font-semibold text-emerald-700">R {{ number_format($match->active_member_fee, 2) }}</dd>
                                </div>
                                @if($match->supportsClubEntry())
                                    {{-- Club and provincial matches: non-members pay the
                                         match-director-set club price (or the member fee
                                         when no club price is set), not the surcharge
                                         price. Only surface the row if it is a different
                                         number from the member fee. --}}
                                    @php
                                        $clubFee = $match->club_entry_fee !== null
                                            ? (float) $match->club_entry_fee
                                            : (float) $match->active_member_fee;
                                    @endphp
                                    @if($clubFee > 0 && $clubFee != (float) $match->active_member_fee)
                                        <div class="flex items-center justify-between">
                                            <dt class="text-xs text-stone-400">Club Entry Fee</dt>
                                            <dd class="text-sm font-medium text-stone-700">R {{ number_format($clubFee, 2) }}</dd>
                                        </div>
                                    @endif
                                @elseif($match->non_member_fee > 0)
                                    <div class="flex items-center justify-between">
                                        <dt class="text-xs text-stone-400">Non-Member Fee</dt>
                                        <dd class="text-sm font-medium text-stone-700">R {{ number_format($match->non_member_fee, 2) }}</dd>
                                    </div>
                                @endif
                                @if($match->status !== 'completed' && $match->registration_status !== 'closed')
                                    <div class="flex items-center justify-between">
                                        <dt class="text-xs text-stone-400">{{ $match->max_competitors ? 'Capacity' : 'Entries' }}</dt>
                                        <dd class="text-sm font-medium text-stone-700">
                                            @if($match->max_competitors)
                                                {{ $match->confirmedRegistrationCount() }} / {{ $match->max_competitors }}
                                            @else
                                                {{ $match->confirmedRegistrationCount() }}
                                            @endif
                                        </dd>
                                    </div>
                                    @if($match->available_slots !== null && $match->available_slots <= 10 && $match->available_slots > 0)
                                        <p class="text-xs font-semibold text-red-600">Only {{ $match->available_slots }} {{ Str::plural('slot', $match->available_slots) }} left</p>
                                    @endif
                                @endif
                            </dl>

                            {{-- Smart CTA --}}
                            <x-event-registration-cta :match="$match" size="lg" class="w-full text-center" />

                            {{-- Sponsor another member: same destination as Register, but hints
                                 the extra action so a sponsor doesn't need to start their own
                                 entry first. The ?sponsor=1 flag tells the controller to open
                                 the sponsor panel — without it, a sponsor who is already
                                 registered themselves would just get bounced to their own
                                 entry (the #fragment doesn't reach the server). Only surfaced
                                 while sign-up is open. --}}
                            @auth
                                @if(in_array($match->registration_status, ['open', 'waitlist'], true))
                                    <a href="{{ url('/events/' . $match->id . '/register') }}?sponsor=1#sponsor"
                                       class="mt-2 inline-flex w-full items-center justify-center gap-1.5 rounded-xl px-4 py-2 text-sm font-medium text-sky-700 hover:bg-sky-50 transition">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" /></svg>
                                        Enter or pay for another member
                                    </a>
                                @endif
                            @endauth

                            @if($match->supportsClubEntry())
                                <p class="mt-3 text-[11px] text-stone-400 text-center">Club entries may not count for season logs. Full members unlock stats, certificate, and firearm tracking.</p>
                            @elseif($match->non_member_fee > 0 && $match->non_member_fee != $match->active_member_fee)
                                <p class="mt-3 text-[11px] text-stone-400 text-center">Non-member scores may not count for season logs.</p>
                            @endif
                        </div>
                    @endif

                    {{-- Phase 3.2: single "At a glance" card replacing the old Stats
                         + Season Info boxes. Stats appear only when they'd carry a
                         non-zero signal (any registration or any score), so an
                         upcoming match with no sign-ups yet stops shouting
                         "Registered: 0" from the sidebar. --}}
                    @php
                        $isImportedHistoric = $match->status === 'completed'
                            && ($match->scores_count ?? 0) > 0
                            && ($match->registrations_count ?? 0) === 0;

                        $hasStatsSignal = ($match->registrations_count ?? 0) > 0
                            || ($match->scores_count ?? 0) > 0;
                    @endphp
                    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
                        <h2 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-3">At a glance</h2>
                        <dl class="space-y-2.5 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-stone-500">Date</dt>
                                <dd class="font-medium text-stone-900 text-right">
                                    @if($match->isMultiDay())
                                        {{ $match->match_date->format('j M') }}–{{ $match->match_end_date->format('j M Y') }}
                                    @else
                                        {{ $match->match_date->format('j M Y') }}
                                    @endif
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-stone-500">Discipline</dt>
                                <dd class="font-medium text-stone-900">{{ $match->match_type }}</dd>
                            </div>
                            @if($match->series_level)
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-stone-500">Level</dt>
                                    <dd class="font-medium text-stone-900 capitalize">{{ $match->series_level }}</dd>
                                </div>
                            @endif
                            @if($match->season)
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-stone-500">Season</dt>
                                    <dd class="font-medium text-stone-900">{{ $match->season }}</dd>
                                </div>
                            @endif
                            @if($match->series)
                                <div class="flex items-center justify-between gap-2">
                                    <dt class="text-stone-500">Series</dt>
                                    <dd class="font-medium text-stone-900">{{ $match->series }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if($hasStatsSignal)
                            <div class="mt-5 pt-5 border-t border-stone-100 grid grid-cols-2 gap-4">
                                @if($isImportedHistoric)
                                    <div>
                                        <p class="text-2xl font-bold text-stone-900">{{ $match->scores_count ?? 0 }}</p>
                                        <p class="text-xs text-stone-400 mt-0.5">Shooters</p>
                                    </div>
                                    <div>
                                        <p class="text-2xl font-bold text-stone-500">—</p>
                                        <p class="text-xs text-stone-400 mt-0.5" title="Historic event imported from external results — no live registrations were captured.">Imported</p>
                                    </div>
                                @else
                                    <div>
                                        <p class="text-2xl font-bold text-stone-900">{{ $match->registrations_count ?? 0 }}</p>
                                        <p class="text-xs text-stone-400 mt-0.5">Registered</p>
                                    </div>
                                    @if($match->status === 'completed')
                                        <div>
                                            <p class="text-2xl font-bold text-stone-900">{{ $match->scores_count ?? 0 }}</p>
                                            <p class="text-xs text-stone-400 mt-0.5">Scored</p>
                                        </div>
                                    @elseif($match->max_competitors)
                                        <div>
                                            <p class="text-2xl font-bold text-stone-900">{{ $match->max_competitors }}</p>
                                            <p class="text-xs text-stone-400 mt-0.5">Max Capacity</p>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @elseif($match->max_competitors)
                            {{-- No sign-ups yet but a published capacity still gives the
                                 shooter useful context ("25 slots available"). --}}
                            <div class="mt-5 pt-5 border-t border-stone-100">
                                <div>
                                    <p class="text-2xl font-bold text-stone-900">{{ $match->max_competitors }}</p>
                                    <p class="text-xs text-stone-400 mt-0.5">Capacity</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

</x-layouts.public>
