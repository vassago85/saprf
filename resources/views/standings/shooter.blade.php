<x-layouts.public :title="$shooter->name . ' - ' . $season . ' Rankings - SAPRF'" :description="$shooter->name . ' — ' . $season . ' SAPRF rankings, match scores, and national points on the official Precision Rifle Federation platform.'" current="standings">
    <div class="bg-stone-50 min-h-screen" x-data="{ active: '{{ $seriesOrder->first() ?? '' }}' }">
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">
                <a href="{{ url('/standings?season=' . $season) }}"
                   class="inline-flex items-center gap-1.5 text-sm text-stone-500 hover:text-emerald-700 transition mb-5">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    Back to Standings
                </a>

                <div class="mb-6">
                    <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $shooter->name }}</h1>
                    <div class="flex items-center gap-3 mt-2">
                        @if($shooter->province)
                            <span class="inline-flex items-center rounded-md bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-600">{{ $shooter->province->name }}</span>
                        @endif
                        <span class="text-sm text-stone-500">{{ $season }} Season</span>
                    </div>
                </div>

                {{-- Series tabs. Each tab is a button showing that series'
                     top-line standings (national + provincial for PR22).
                     Clicking a tab reveals the detailed card for that series
                     below and hides the other. This is the primary
                     navigation on this page — a PRS tab NEVER shows PR22
                     data and vice-versa. --}}
                @if($seriesOrder->isNotEmpty())
                    <div class="grid grid-cols-1 @if($seriesOrder->count() >= 2) sm:grid-cols-2 @endif gap-3">
                        @foreach($seriesOrder as $series)
                            @php
                                $tabEntry = $summaryBySeries[$series] ?? null;
                                $tabMatches = ($scoresBySeries[$series] ?? collect())->count();
                                // Explicit class strings (not interpolated) so
                                // the Tailwind JIT sees both variants as
                                // literal tokens in the source and includes
                                // them in the build.
                                $activeClasses = $series === 'PRS'
                                    ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-400 shadow-sm'
                                    : 'border-sky-500 bg-sky-50 ring-2 ring-sky-400 shadow-sm';
                                $headingClass = $series === 'PRS' ? 'text-emerald-700' : 'text-sky-700';
                            @endphp
                            <button type="button"
                                    @click="active = '{{ $series }}'"
                                    :class="active === '{{ $series }}'
                                        ? '{{ $activeClasses }}'
                                        : 'border-stone-200 bg-white hover:border-stone-300'"
                                    class="rounded-2xl border-2 p-5 text-left transition cursor-pointer">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-bold uppercase tracking-wider {{ $headingClass }}">{{ $series }} Standings</span>
                                        <x-discipline-chip :discipline="$series" />
                                    </div>
                                    <span x-show="active === '{{ $series }}'" x-cloak class="text-[10px] font-semibold uppercase tracking-wider {{ $headingClass }} flex items-center gap-1">
                                        <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        Viewing
                                    </span>
                                    <span x-show="active !== '{{ $series }}'" class="text-[10px] font-semibold uppercase tracking-wider text-stone-400">Click to view</span>
                                </div>

                                @if($tabEntry && $tabEntry['overall_rank'] !== null)
                                    <div class="flex items-start gap-6 flex-wrap">
                                        <div>
                                            <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-600">National</p>
                                            <div class="flex items-baseline gap-2">
                                                <p class="text-3xl font-bold text-stone-900">#{{ $tabEntry['overall_rank'] }}</p>
                                                <p class="text-sm text-stone-500 tabular-nums">{{ number_format($tabEntry['overall_points'] ?? 0, 2) }} pts</p>
                                            </div>
                                            {{-- One chip per division the shooter competed in. A single shooter
                                                 may have several (e.g. Open in one match, Factory in another) and
                                                 each is ranked independently. --}}
                                            @if(!empty($tabEntry['divisions']))
                                                <div class="mt-0.5 flex flex-wrap gap-x-2 gap-y-0 text-[10px] text-stone-400">
                                                    @foreach($tabEntry['divisions'] as $div)
                                                        <span>{{ $div['name'] }}: <span class="font-bold text-amber-700">#{{ $div['rank'] ?? '—' }}</span></span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                        @if(!empty($tabEntry['has_provincial']))
                                            <div class="border-l border-stone-200 pl-6">
                                                <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-600">
                                                    Provincial @if($tabEntry['province_name'])<span class="text-blue-400">&middot; {{ $tabEntry['province_name'] }}</span>@endif
                                                </p>
                                                <div class="flex items-baseline gap-2">
                                                    <p class="text-3xl font-bold text-stone-900">#{{ $tabEntry['provincial_rank'] ?? '—' }}</p>
                                                    <p class="text-sm text-stone-500 tabular-nums">{{ number_format($tabEntry['provincial_points'] ?? 0, 2) }} pts</p>
                                                </div>
                                                @if(!empty($tabEntry['provincial_divisions']))
                                                    <div class="mt-0.5 flex flex-wrap gap-x-2 gap-y-0 text-[10px] text-stone-400">
                                                        @foreach($tabEntry['provincial_divisions'] as $div)
                                                            <span>{{ $div['name'] }}: <span class="font-bold text-amber-700">#{{ $div['rank'] ?? '—' }}</span></span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    <p class="text-sm text-stone-500">
                                        {{ $tabMatches }} match{{ $tabMatches === 1 ? '' : 'es' }} attended <span class="text-stone-400">&mdash; not ranked</span>
                                    </p>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 space-y-6">
            @php
                // Membership-eligibility badges. These describe the shooter's
                // membership state on match day, NOT whether the score
                // actually contributed to a ranking. Whether a score counted
                // toward the season is shown separately in the Nat. Pts /
                // Prov. Pts columns ("+X.XX" if counted, "DROPPED" if valid
                // but not among the counting matches). A previous "Counts"
                // label conflicted with the DROPPED indicator and read as
                // contradictory to shooters.
                $statusBadges = [
                    'valid' => [
                        'label' => 'Eligible',
                        'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                        'tooltip' => 'You were a paid member on match day — this score was eligible for the season log. Look at the National (and Provincial) contribution columns to see if it actually counted toward your ranking.',
                    ],
                    'pending' => [
                        'label' => 'Pending',
                        'class' => 'bg-amber-50 text-amber-700 ring-amber-200',
                        'tooltip' => 'Membership payment was pending on match day — score is not eligible for the season log.',
                    ],
                    'lapsed' => [
                        'label' => 'Lapsed',
                        'class' => 'bg-orange-50 text-orange-700 ring-orange-200',
                        'tooltip' => 'Membership was lapsed on match day — score is not eligible for the season log.',
                    ],
                    'non_member' => [
                        'label' => 'Non-member',
                        'class' => 'bg-stone-100 text-stone-500 ring-stone-200',
                        'tooltip' => 'You were not a member on match day — score is visible for reference but not eligible for the season log.',
                    ],
                ];
            @endphp
            @foreach($seriesOrder as $series)
                @php
                    $entry = $summaryBySeries[$series] ?? null;
                    $seriesScores = ($scoresBySeries[$series] ?? collect())->values();
                @endphp
                {{-- Only the currently-selected series tab's card is shown.
                     This keeps a PRS view free of any PR22 detail (and
                     vice-versa) with no possibility of visual confusion. --}}
                <div x-show="active === '{{ $series }}'" x-cloak
                     class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-stone-100 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <h2 class="text-lg font-semibold text-stone-900">{{ $series }} @if($entry) Rankings @else Matches @endif</h2>
                            <x-discipline-chip :discipline="$series" />
                        </div>
                        @if($entry && ($entry['scoring_mode'] ?? null) === 'weighted_pools')
                            <span class="text-xs text-stone-400">Weighted pool total (out of 100)</span>
                        @elseif($entry && ($entry['pool_breakdown']['mode'] ?? null) === 'annual_log')
                            <span class="text-xs text-stone-400">Best 3 nationals + SA Champs (out of 400)</span>
                        @elseif($entry && $bestOf)
                            <span class="text-xs text-stone-400">Best {{ $bestOf }} scores count</span>
                        @elseif(! $entry)
                            <span class="text-xs text-stone-400">{{ $seriesScores->count() }} match{{ $seriesScores->count() === 1 ? '' : 'es' }} attended — not ranked</span>
                        @endif
                    </div>

                    @if($entry)

                    {{-- Annual "national log" breakdown card (best-N regular + fixed champs, e.g. PRS) --}}
                    @if(($entry['pool_breakdown']['mode'] ?? null) === 'annual_log')
                        @php $pb = $entry['pool_breakdown']; @endphp
                        <div class="px-6 py-5 border-b border-stone-100 bg-gradient-to-br from-stone-50 to-white">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-stone-500 mb-3">Annual Log Breakdown</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4">
                                    <span class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">Best {{ $pb['regular_best_of'] ?? 3 }} Regular</span>
                                    <div class="mt-2 flex items-baseline gap-1">
                                        <span class="text-2xl font-bold text-emerald-800 tabular-nums">{{ number_format($pb['regular_total'] ?? 0, 2) }}</span>
                                        <span class="text-xs text-stone-400">/ {{ ($pb['regular_best_of'] ?? 3) * 100 }}</span>
                                    </div>
                                    <div class="mt-2 space-y-1 text-[11px] text-stone-500">
                                        @forelse($pb['regular'] ?? [] as $reg)
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="truncate">
                                                    {{ $reg['match_name'] ?? ('Match #'.$reg['match_id']) }}
                                                    @if(!empty($matchDates[$reg['match_id']]))
                                                        <span class="text-stone-400">&middot; {{ $matchDates[$reg['match_id']]->format('d M Y') }}</span>
                                                    @endif
                                                </span>
                                                <span class="font-mono">{{ number_format($reg['pct'], 2) }}%</span>
                                            </div>
                                        @empty
                                            <div class="text-stone-400">No regular matches counted.</div>
                                        @endforelse
                                    </div>
                                </div>
                                <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-4">
                                    <span class="text-xs font-semibold text-amber-700 uppercase tracking-wider">SA Champs</span>
                                    <div class="mt-2 flex items-baseline gap-1">
                                        <span class="text-2xl font-bold text-amber-800 tabular-nums">{{ number_format($pb['champs_pct'] ?? 0, 2) }}</span>
                                        <span class="text-xs text-stone-400">/ 100</span>
                                    </div>
                                    <div class="mt-2 text-[11px] text-stone-500">
                                        @if(!empty($pb['champs']))
                                            <span class="truncate">
                                                {{ $pb['champs']['match_name'] ?? ('Match #'.$pb['champs']['match_id']) }}
                                                @if(!empty($matchDates[$pb['champs']['match_id']]))
                                                    <span class="text-stone-400">&middot; {{ $matchDates[$pb['champs']['match_id']]->format('d M Y') }}</span>
                                                @endif
                                            </span>
                                        @else
                                            <span class="text-stone-400">Champs not shot — fixed at 0 (cannot be replaced by a regular match).</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="rounded-lg bg-stone-900 text-white px-4 py-3 flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider">Annual Total</span>
                                <span class="text-2xl font-bold tabular-nums">{{ number_format($pb['total'] ?? ($entry['overall_points'] ?? 0), 2) }} / {{ $pb['max'] ?? 400 }}</span>
                            </div>
                        </div>
                    {{-- Best-of-N breakdown card. Used for any series whose
                         QualificationRule is missing or set to a plain best-of-N
                         (e.g. PRS 2026 while the annual-log rule isn't yet
                         configured). Renders a single-pool summary with every
                         match's contribution so the "counted" and "dropped"
                         labels in the table below line up with the season total. --}}
                    @elseif(($entry['pool_breakdown']['mode'] ?? null) === 'best_of_n')
                        @php
                            $pb = $entry['pool_breakdown'];
                            $bpBestOf = $pb['best_of'] ?? null;
                            $bpMatches = collect($pb['matches'] ?? []);
                            $bpCounted = $bpMatches->where('counted', true);
                            $bpMax = $bpBestOf ? ($bpBestOf * 100) : null;
                        @endphp
                        <div class="px-6 py-5 border-b border-stone-100 bg-gradient-to-br from-stone-50 to-white">
                            <div class="flex items-baseline justify-between mb-3">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-emerald-700">National Standing Breakdown</h3>
                                <span class="text-[10px] text-stone-400">
                                    @if($bpBestOf)
                                        Sum of your best {{ $bpBestOf }} counting match scores
                                    @else
                                        Sum of all your counting match scores
                                    @endif
                                </span>
                            </div>
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-4 mb-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-semibold text-emerald-700 uppercase tracking-wider">
                                        @if($bpBestOf) Best {{ $bpBestOf }} Matches @else Counted Matches @endif
                                    </span>
                                    <span class="text-[10px] font-mono text-stone-500">{{ $bpCounted->count() }}/{{ $bpMatches->count() }} counted</span>
                                </div>
                                <div class="mt-2 flex items-baseline gap-1">
                                    <span class="text-2xl font-bold text-emerald-800 tabular-nums">{{ number_format((float) ($pb['total'] ?? ($entry['overall_points'] ?? 0)), 2) }}</span>
                                    @if($bpMax)
                                        <span class="text-xs text-stone-400">/ {{ $bpMax }}</span>
                                    @endif
                                </div>
                                <div class="mt-3 space-y-1 text-[11px] text-stone-500">
                                    @forelse($bpMatches->sortByDesc('pct') as $row)
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="truncate">
                                                {{ $row['match_name'] ?? ('Match #'.$row['match_id']) }}
                                                @if(!empty($matchDates[$row['match_id']]))
                                                    <span class="text-stone-400">&middot; {{ $matchDates[$row['match_id']]->format('d M Y') }}</span>
                                                @endif
                                                @if(!empty($row['series_level']))
                                                    <span class="text-stone-400">&middot; {{ ucfirst((string) $row['series_level']) }}</span>
                                                @endif
                                            </span>
                                            <span class="flex items-center gap-2">
                                                <span class="font-mono">{{ number_format((float) ($row['pct'] ?? 0), 2) }}%</span>
                                                @if(!empty($row['counted']))
                                                    <span class="text-emerald-700 font-semibold">counted</span>
                                                @else
                                                    <span class="text-stone-400">dropped</span>
                                                @endif
                                            </span>
                                        </div>
                                    @empty
                                        <div class="text-stone-400">No counting matches found in this series.</div>
                                    @endforelse
                                </div>
                            </div>
                            <div class="rounded-lg bg-stone-900 text-white px-4 py-3 flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider">National Season Total</span>
                                <span class="text-2xl font-bold tabular-nums" title="Equals the sum of the Nat. Pts column in the match table below">{{ number_format($entry['overall_points'] ?? 0, 2) }}@if($bpMax) / {{ $bpMax }}@endif</span>
                            </div>
                        </div>

                    {{-- Pool breakdown card (weighted-pools mode, e.g. PR22 NATIONAL standing).
                         Match either the explicit mode key (set on new rows)
                         or the presence of any pool bucket (for legacy rows
                         persisted before the mode key was added). --}}
                    @elseif(($entry['pool_breakdown']['mode'] ?? null) === 'weighted_pools'
                        || isset($entry['pool_breakdown']['provincial'])
                        || isset($entry['pool_breakdown']['national'])
                        || isset($entry['pool_breakdown']['champs']))
                        @php
                            $pb = $entry['pool_breakdown'];
                            // Each pool is one of three "buckets" of matches
                            // inside the NATIONAL standing. The labels are
                            // spelled out so the reader can never mistake the
                            // "provincial-matches pool" (inside national) for
                            // the separate provincial standing block below.
                            $poolMeta = [
                                'provincial' => [
                                    'label' => 'Provincial matches',
                                    'card' => 'border-sky-200 bg-sky-50/50',
                                    'chip' => 'text-sky-700',
                                    'value' => 'text-sky-800',
                                ],
                                'national' => [
                                    'label' => 'National matches',
                                    'card' => 'border-emerald-200 bg-emerald-50/50',
                                    'chip' => 'text-emerald-700',
                                    'value' => 'text-emerald-800',
                                ],
                                'champs' => [
                                    'label' => 'SA Champs',
                                    'card' => 'border-amber-200 bg-amber-50/50',
                                    'chip' => 'text-amber-700',
                                    'value' => 'text-amber-800',
                                ],
                            ];
                        @endphp
                        <div class="px-6 py-5 border-b border-stone-100 bg-gradient-to-br from-stone-50 to-white">
                            <div class="flex items-baseline justify-between mb-3">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-emerald-700">National Standing Breakdown</h3>
                                <span class="text-[10px] text-stone-400">Weighted average of your {{ $series }} matches (out of 100)</span>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
                                @foreach($poolMeta as $key => $meta)
                                    @if(isset($pb[$key]))
                                        @php $pool = $pb[$key]; @endphp
                                        <div class="rounded-xl border {{ $meta['card'] }} p-4">
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-semibold {{ $meta['chip'] }} uppercase tracking-wider">{{ $meta['label'] }}</span>
                                                <span class="text-[10px] font-mono text-stone-500">×{{ (int) $pool['weight_pct'] }}%</span>
                                            </div>
                                            <div class="mt-2 flex items-baseline gap-1">
                                                <span class="text-2xl font-bold {{ $meta['value'] }} tabular-nums">{{ number_format($pool['contribution'], 1) }}</span>
                                                <span class="text-xs text-stone-400">/ {{ (int) $pool['weight_pct'] }}</span>
                                            </div>
                                            <div class="mt-1 text-[11px] text-stone-500">
                                                Best {{ $pool['best_of'] }} avg: <span class="font-mono">{{ number_format($pool['pool_average'], 1) }}%</span>
                                                <span class="text-stone-400">({{ $pool['scores_counted'] }}/{{ $pool['best_of'] }} counted)</span>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                            <div class="rounded-lg bg-stone-900 text-white px-4 py-3 flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider">National Season Total</span>
                                <span class="text-2xl font-bold tabular-nums" title="Equals the sum of the Nat. Pts column in the match table below">{{ number_format($entry['overall_points'] ?? 0, 2) }} / 100</span>
                            </div>
                        </div>

                        {{-- Provincial standing (PR22 only). Rendered as its
                             own visually distinct block so nothing here can be
                             read as part of the national standing above. --}}
                        @if(!empty($entry['has_provincial']))
                            @php
                                $ppb = $entry['provincial_pool_breakdown'] ?? [];
                                $ppMatches = collect($ppb['matches'] ?? []);
                                $ppCounted = $ppMatches->where('counted', true);
                                $ppBestOf = $ppb['best_of'] ?? null;
                                $ppMax = $ppBestOf ? ($ppBestOf * 100) : null;
                            @endphp
                            <div class="px-6 py-5 border-b border-stone-100 bg-gradient-to-br from-blue-50/40 to-white">
                                <div class="flex items-baseline justify-between mb-3">
                                    <h3 class="text-xs font-semibold uppercase tracking-wider text-blue-700">
                                        Provincial Standing Breakdown
                                        @if($entry['province_name'])
                                            <span class="text-blue-400 font-normal normal-case">&middot; {{ $entry['province_name'] }}</span>
                                        @endif
                                    </h3>
                                    <span class="text-[10px] text-stone-400">
                                        @if($ppBestOf)
                                            Sum of your best {{ $ppBestOf }} provincial-match scores
                                        @else
                                            Sum of your qualifying provincial-match scores
                                        @endif
                                    </span>
                                </div>
                                <div class="rounded-xl border border-blue-200 bg-white/60 p-4 mb-3">
                                    <div class="text-[11px] text-stone-500 space-y-1">
                                        @forelse($ppCounted as $m)
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="truncate">
                                                    {{ $m['match_name'] ?? ('Match #'.$m['match_id']) }}
                                                    @if(!empty($matchDates[$m['match_id']]))
                                                        <span class="text-stone-400">&middot; {{ $matchDates[$m['match_id']]->format('d M Y') }}</span>
                                                    @endif
                                                </span>
                                                <span class="font-mono text-blue-800 font-semibold">{{ number_format((float) $m['contribution'], 2) }}</span>
                                            </div>
                                        @empty
                                            <div class="text-stone-400">No provincial matches counted yet.</div>
                                        @endforelse
                                    </div>
                                </div>
                                <div class="rounded-lg bg-blue-900 text-white px-4 py-3 flex items-center justify-between">
                                    <span class="text-xs font-semibold uppercase tracking-wider">Provincial Season Total</span>
                                    <span class="text-2xl font-bold tabular-nums" title="Equals the sum of the Prov. Pts column in the match table below">
                                        {{ number_format((float) ($entry['provincial_points'] ?? 0), 2) }}@if($ppMax)<span class="text-xs text-blue-200"> / {{ $ppMax }}</span>@endif
                                    </span>
                                </div>
                            </div>
                        @endif
                    @endif

                    {{-- Per-division breakdowns. The overall breakdowns above
                         show which matches contributed to the shooter's
                         *overall* National/Provincial totals (best-N across
                         every division). These panels answer the natural
                         follow-up question — "how did I get Open #2
                         (279.45) and Factory #2 (292.14)?" — by listing the
                         counted matches restricted to each division
                         cohort. Only shown when the shooter placed in more
                         than one division for the series (single-division
                         shooters would just see the overall breakdown
                         repeated). Rendered OUTSIDE the annual_log /
                         best_of_n / weighted_pools mode chain above so
                         every series (PRS annual_log, PR22 weighted_pools,
                         plain best_of_n) benefits from it. --}}
                    @if($entry)
                        @php
                            $natDivs = collect($entry['divisions'] ?? [])->filter(fn ($d) => ! empty($d['pool_breakdown']))->values();
                            $provDivs = collect($entry['provincial_divisions'] ?? [])->filter(fn ($d) => ! empty($d['pool_breakdown']))->values();
                        @endphp

                        @if($natDivs->count() > 1)
                            <div class="px-6 py-5 border-b border-stone-100 bg-emerald-50/10">
                                <div class="flex items-baseline justify-between mb-3">
                                    <h3 class="text-xs font-semibold uppercase tracking-wider text-emerald-700">National Division Breakdown</h3>
                                    <span class="text-[10px] text-stone-400">Which of your matches counted toward each division rank</span>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    @foreach($natDivs as $div)
                                        <x-division-breakdown-panel
                                            :division="$div"
                                            :matchDates="$matchDates"
                                            accent="emerald"
                                        />
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if($provDivs->count() > 1)
                            <div class="px-6 py-5 border-b border-stone-100 bg-blue-50/10">
                                <div class="flex items-baseline justify-between mb-3">
                                    <h3 class="text-xs font-semibold uppercase tracking-wider text-blue-700">
                                        Provincial Division Breakdown
                                        @if($entry['province_name'])
                                            <span class="text-blue-400 font-normal normal-case">&middot; {{ $entry['province_name'] }}</span>
                                        @endif
                                    </h3>
                                    <span class="text-[10px] text-stone-400">Which of your matches counted toward each division rank</span>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    @foreach($provDivs as $div)
                                        <x-division-breakdown-panel
                                            :division="$div"
                                            :matchDates="$matchDates"
                                            accent="blue"
                                        />
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif

                    <div class="px-6 py-4 border-b border-stone-50 bg-stone-50/30">
                        <div class="grid grid-cols-1 @if(!empty($entry['has_provincial'])) md:grid-cols-2 @endif gap-4">
                            {{-- National standing rank/points --}}
                            @if($entry['overall_rank'] !== null)
                                <div class="text-center @if(!empty($entry['has_provincial'])) md:border-r md:border-stone-200 @endif">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-700 mb-1">{{ $series }} National</p>
                                    <div class="flex items-start justify-center gap-6 flex-wrap">
                                        <div title="Ranking against every {{ $series }} shooter in the season">
                                            <p class="text-xs text-stone-400">Overall</p>
                                            <p class="text-xl font-bold text-stone-900">#{{ $entry['overall_rank'] }}</p>
                                            <p class="text-xs text-stone-500 tabular-nums">{{ number_format($entry['overall_points'] ?? 0, 2) }} pts</p>
                                            <p class="text-[9px] text-stone-400 uppercase tracking-wider">vs everyone</p>
                                        </div>
                                        {{-- One tile per division the shooter placed in. Each division has
                                             its own independent normalisation (top of that division = 100%),
                                             so the points here will never sum to the Overall total. --}}
                                        @foreach($entry['divisions'] ?? [] as $div)
                                            <div title="Separate ranking computed using {{ $div['name'] }}-only normalization (each match's top {{ $div['name'] }} shooter = 100%). Points here will not match the Overall total.">
                                                <p class="text-xs text-stone-400">{{ $div['name'] }}</p>
                                                <p class="text-xl font-bold text-amber-700">#{{ $div['rank'] ?? '—' }}</p>
                                                <p class="text-xs text-stone-500 tabular-nums">{{ number_format($div['points'] ?? 0, 2) }} pts</p>
                                                <p class="text-[9px] text-stone-400 uppercase tracking-wider">division only</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Provincial standing rank/points (PR22 only) --}}
                            @if(!empty($entry['has_provincial']))
                                <div class="text-center">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-700 mb-1">
                                        {{ $series }} Provincial
                                        @if($entry['province_name'])
                                            <span class="text-blue-400">&middot; {{ $entry['province_name'] }}</span>
                                        @endif
                                    </p>
                                    <div class="flex items-start justify-center gap-6 flex-wrap">
                                        <div title="Ranking against every {{ $entry['province_name'] ?? 'in-province' }} {{ $series }} shooter">
                                            <p class="text-xs text-stone-400">Overall</p>
                                            <p class="text-xl font-bold text-stone-900">#{{ $entry['provincial_rank'] ?? '—' }}</p>
                                            <p class="text-xs text-stone-500 tabular-nums">{{ number_format($entry['provincial_points'] ?? 0, 2) }} pts</p>
                                            <p class="text-[9px] text-stone-400 uppercase tracking-wider">vs everyone</p>
                                        </div>
                                        @foreach($entry['provincial_divisions'] ?? [] as $div)
                                            <div title="Separate ranking computed using {{ $div['name'] }}-only normalization. Points here will not match the Overall total.">
                                                <p class="text-xs text-stone-400">{{ $div['name'] }}</p>
                                                <p class="text-xl font-bold text-amber-700">#{{ $div['rank'] ?? '—' }}</p>
                                                <p class="text-xs text-stone-500 tabular-nums">{{ number_format($div['points'] ?? 0, 2) }} pts</p>
                                                <p class="text-[9px] text-stone-400 uppercase tracking-wider">division only</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                    @endif

                    @php
                        // Show the Prov. Pts contribution column whenever the
                        // shooter has a provincial standing for this series.
                        // Both PRS and PR22 now have provincial standings (sum
                        // of best-N provincial-level scores). A shooter with
                        // no provincial standing (e.g. no home province set, or
                        // no provincial-level scores this season) still sees
                        // the single Nat. Pts column.
                        $hasProvincialCol = ! empty($entry['has_provincial']);
                        // Column count for empty/tfoot rows: 7 base cols
                        // (Date, Match, Level, Division, #, Impacts, %Score)
                        // + Membership + 1 or 2 contribution cols.
                        $tableColCount = $hasProvincialCol ? 10 : 9;
                    @endphp
                    <div class="px-6 pt-4 pb-1">
                        <p class="text-xs text-stone-400">
                            All <span class="font-semibold text-stone-600">{{ $series }}</span> matches this shooter attended in {{ $season }}.
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-stone-200 bg-stone-50/50">
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden md:table-cell">Level</th>
                                    <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden sm:table-cell">Division</th>
                                    <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400">#</th>
                                    <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Impacts</th>
                                    <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">% Score</th>
                                    <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-emerald-700 bg-emerald-50/40" title="Points this match contributed toward the {{ $series }} National standing">Nat. Pts</th>
                                    @if($hasProvincialCol)
                                        <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-blue-700 bg-blue-50/40" title="Points this match contributed toward the {{ $series }} Provincial standing">Prov. Pts</th>
                                    @endif
                                    <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400" title="Membership eligibility on match day. Independent of whether the score counted — the contribution columns to the left show that separately.">Membership</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($seriesScores as $score)
                                    @php
                                        $badge = $statusBadges[$score->status] ?? $statusBadges['non_member'];
                                        $level = $score->match?->series_level;
                                        $levelLabel = match($level) {
                                            'national' => 'National',
                                            'provincial' => 'Provincial',
                                            'final' => 'SA Champs',
                                            default => ucfirst((string) $level),
                                        };
                                        $contribution = $contributionByMatch[$score->match_id] ?? null;
                                        $countedNat = (bool) ($contribution['counted_national'] ?? false);
                                        $countedProv = (bool) ($contribution['counted_provincial'] ?? false);
                                        $isValid = $score->status === 'valid';

                                        // Only provincial-level matches feed the
                                        // provincial standing. A national match
                                        // (even a 2-day national) stays national
                                        // — if day-1 should count provincially,
                                        // the MD posts it as its own separate
                                        // provincial match.
                                        $feedsProvincial = $level === 'provincial';
                                    @endphp
                                    <tr class="border-b border-stone-50 hover:bg-stone-50/50">
                                        <td class="px-5 py-3 text-sm text-stone-500 whitespace-nowrap">{{ $score->match?->match_date?->format('j M') }}</td>
                                        <td class="px-5 py-3">
                                            <a href="{{ url('/events/' . $score->match_id) }}" class="text-sm font-medium text-stone-900 hover:text-emerald-700 transition">
                                                {{ $score->match?->name ?? 'Unknown' }}
                                            </a>
                                            <span class="md:hidden block text-[11px] text-stone-400">{{ $levelLabel }}</span>
                                        </td>
                                        <td class="px-5 py-3 hidden md:table-cell text-sm text-stone-500">{{ $levelLabel }}</td>
                                        <td class="px-5 py-3 hidden sm:table-cell text-sm text-stone-500">{{ $score->division?->name ?? '—' }}</td>
                                        <td class="px-5 py-3 text-center">
                                            @if($score->overall_rank && $score->overall_rank <= 3)
                                                @php $medal = match($score->overall_rank) { 1 => 'bg-amber-100 text-amber-700', 2 => 'bg-stone-200 text-stone-600', 3 => 'bg-amber-50 text-amber-600', default => '' }; @endphp
                                                <span class="inline-flex items-center justify-center size-6 rounded-full {{ $medal }} text-xs font-bold">{{ $score->overall_rank }}</span>
                                            @else
                                                <span class="text-sm text-stone-400">{{ $score->overall_rank ?? '—' }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-right text-sm text-stone-700 tabular-nums">{{ number_format($score->raw_score, 1) }}</td>
                                        <td class="px-5 py-3 text-right text-sm font-bold text-stone-700 tabular-nums">{{ number_format($score->normalized_score, 2) }}</td>

                                        {{-- National standing contribution --}}
                                        <td class="px-5 py-3 text-center whitespace-nowrap bg-emerald-50/20">
                                            @if($countedNat)
                                                <span class="text-sm font-bold text-emerald-700 tabular-nums" title="Points contributed to {{ $series }} National standing">
                                                    +{{ number_format((float) $contribution['national_pts'], 2) }}
                                                </span>
                                            @elseif($isValid)
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset bg-stone-100 text-stone-400 ring-stone-200" title="Valid score, but not among the counting matches for the National standing">DROPPED</span>
                                            @else
                                                <span class="text-stone-300">—</span>
                                            @endif
                                        </td>

                                        {{-- Provincial standing contribution (PR22 only) --}}
                                        @if($hasProvincialCol)
                                            <td class="px-5 py-3 text-center whitespace-nowrap bg-blue-50/20">
                                                @if(!$feedsProvincial)
                                                    <span class="text-stone-300" title="This match does not feed the Provincial standing">—</span>
                                                @elseif($countedProv)
                                                    <span class="text-sm font-bold text-blue-700 tabular-nums" title="Points contributed to {{ $series }} Provincial standing">
                                                        +{{ number_format((float) $contribution['provincial_pts'], 2) }}
                                                    </span>
                                                @elseif($isValid)
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset bg-stone-100 text-stone-400 ring-stone-200" title="Valid score, but not among the counting matches for the Provincial standing">DROPPED</span>
                                                @else
                                                    <span class="text-stone-300">—</span>
                                                @endif
                                            </td>
                                        @endif

                                        <td class="px-5 py-3 text-center">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset {{ $badge['class'] }}" title="{{ $badge['tooltip'] ?? '' }}">{{ strtoupper($badge['label']) }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $tableColCount }}" class="px-5 py-8 text-center text-sm text-stone-400">No matches attended in this series.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if($seriesScores->isNotEmpty())
                                <tfoot class="border-t-2 border-stone-200 bg-stone-50/50">
                                    <tr>
                                        <td colspan="6" class="px-5 py-3 text-sm font-semibold text-stone-700">
                                            {{ $seriesScores->count() }} match{{ $seriesScores->count() === 1 ? '' : 'es' }} attended
                                        </td>
                                        <td class="px-5 py-3 text-right text-sm font-bold text-stone-600 tabular-nums" title="Best % score">{{ number_format($seriesScores->max('normalized_score'), 2) }}</td>
                                        {{-- Nat total. The tiny "Nat total" caption above the number
                                             is deliberately redundant with the column header — the
                                             blue/emerald colour distinction alone reads as ambiguous
                                             for anyone scanning just the footer row. --}}
                                        <td class="px-5 py-3 text-center bg-emerald-50/20" title="Sum of Nat. Pts = your {{ $series }} National OVERALL standing points ({{ number_format($entry['overall_points'] ?? 0, 2) }}). Division-only standings use the same values, so if you shot one division all season, this sum also equals your division standing.">
                                            <div class="text-[9px] font-semibold uppercase tracking-wider text-emerald-600 leading-none">Nat total</div>
                                            <div class="text-sm font-bold text-emerald-700 tabular-nums mt-0.5">
                                                {{ number_format(collect($seriesScores)->sum(fn($s) => (float) ($contributionByMatch[$s->match_id]['national_pts'] ?? 0)), 2) }}
                                            </div>
                                        </td>
                                        @if($hasProvincialCol)
                                            <td class="px-5 py-3 text-center bg-blue-50/20" title="Sum of Prov. Pts = your {{ $series }} Provincial OVERALL standing points ({{ number_format($entry['provincial_points'] ?? 0, 2) }}). Division-only standings use the same values, so if you shot one division all season, this sum also equals your division standing.">
                                                <div class="text-[9px] font-semibold uppercase tracking-wider text-blue-600 leading-none">Prov total</div>
                                                <div class="text-sm font-bold text-blue-700 tabular-nums mt-0.5">
                                                    {{ number_format(collect($seriesScores)->sum(fn($s) => (float) ($contributionByMatch[$s->match_id]['provincial_pts'] ?? 0)), 2) }}
                                                </div>
                                            </td>
                                        @endif
                                        <td></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            @endforeach

            @if($seriesOrder->isEmpty())
                <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-12 text-center">
                    <h3 class="text-lg font-semibold text-stone-700">No matches yet</h3>
                    <p class="mt-1 text-sm text-stone-400">This shooter has no recorded matches in the {{ $season }} season.</p>
                </div>
            @endif

        </div>
    </div>

</x-layouts.public>
