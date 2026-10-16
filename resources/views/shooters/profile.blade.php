<x-layouts.public
    :title="$shooter->name . ' — SAPRF Shooter Profile'"
    :description="$shooter->name . ' — SAPRF shooter career profile. Rankings, national-team appearances, gear and match history on the official Precision Rifle Federation platform.'"
    current="shooters">

    @php
        // saprf_number is what canonical /shooters/{saprfNumber} URLs are keyed
        // on; fall back to the user's ID for the tiny minority of profiles
        // that reach this template without a membership row.
        $saprfNumber = $shooter->membership?->saprf_number ?? $shooter->id;
        // A shooter's currently active season for the header context. If
        // the payload has no ranking summary at all (a brand-new shooter),
        // we still want the season switcher tabs to render — hence the
        // union of $availableSeasons with the requested $season.
        $seasonsForSwitcher = collect([$season])
            ->merge($availableSeasons ?? collect())
            ->filter()
            ->map(fn ($s) => (string) $s)
            ->unique()
            ->sortDesc()
            ->values();
        $stats = $careerStats ?? [];
    @endphp

    <div class="bg-stone-50 min-h-screen" x-data="{ active: '{{ $seriesOrder->first() ?? '' }}' }">
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">

                {{-- Identity header. Simpler than the legacy /standings/… view
                     — no back link, because this is a top-level canonical
                     URL, not a drill-down. --}}
                @php
                    // SA representation years derived from the full appearance
                    // list already loaded by ShooterProfileService::career().
                    // The chip only records that they represented South Africa
                    // in those years — Protea vs Federation colours stay off
                    // the public profile until the historical awards are confirmed.
                    $headerSaYears = ($nationalTeamAppearances ?? collect())
                        ->pluck('year')
                        ->filter()
                        ->map(fn ($y) => (int) $y)
                        ->unique()
                        ->sortDesc()
                        ->values()
                        ->all();
                    $headerSaTooltip = null;
                    if ($latestAppearance = ($nationalTeamAppearances ?? collect())->first()) {
                        $headerSaTooltip = trim(implode(' · ', array_filter([
                            $latestAppearance->division_label ?: $latestAppearance->divisionName(),
                            $latestAppearance->championship_name,
                        ])));
                    }
                @endphp
                <div class="mb-6">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $shooter->name }}</h1>
                        <x-sa-team-badge :years="$headerSaYears" :tooltip="$headerSaTooltip" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2 mt-2">
                        @if($shooter->province)
                            <span class="inline-flex items-center rounded-md bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-600">{{ $shooter->province->name }}</span>
                        @endif
                        @if($saprfNumber && is_numeric($saprfNumber))
                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-100" title="SAPRF membership number">
                                SAPRF #{{ $saprfNumber }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Career totals bar. All values are cheap aggregate counts
                     over the shooter's lifetime visible score history —
                     zero rows renders "—" rather than "0" so the profile
                     doesn't look statistical for brand-new shooters. --}}
                @if(($stats['matches_attended'] ?? 0) > 0)
                    <div class="mb-6 grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-2">
                        <div class="rounded-lg border border-stone-200 bg-white px-3 py-2.5">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-stone-400">Matches</p>
                            <p class="text-lg font-bold text-stone-900 tabular-nums">{{ $stats['matches_attended'] }}</p>
                        </div>
                        <div class="rounded-lg border border-stone-200 bg-white px-3 py-2.5">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-stone-400">Best %</p>
                            <p class="text-lg font-bold text-stone-900 tabular-nums">
                                {{ $stats['best_percent'] !== null ? number_format($stats['best_percent'], 2) : '—' }}
                            </p>
                        </div>
                        <div class="rounded-lg border border-stone-200 bg-white px-3 py-2.5">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-stone-400" title="Times finished 1st in a match">Wins</p>
                            <p class="text-lg font-bold text-amber-700 tabular-nums">{{ $stats['wins'] ?? 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-stone-200 bg-white px-3 py-2.5">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-stone-400" title="Top-3 finishes at national + SA Champs matches">Nat. Podiums</p>
                            <p class="text-lg font-bold text-emerald-700 tabular-nums">{{ $stats['national_podiums'] ?? 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-stone-200 bg-white px-3 py-2.5">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-stone-400" title="Top-3 finishes at provincial matches">Prov. Podiums</p>
                            <p class="text-lg font-bold text-blue-700 tabular-nums">{{ $stats['provincial_podiums'] ?? 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-stone-200 bg-white px-3 py-2.5">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-stone-400">Seasons</p>
                            <p class="text-lg font-bold text-stone-900 tabular-nums">{{ $stats['seasons_active'] ?? 0 }}</p>
                            @if(!empty($stats['first_match_date']) && !empty($stats['latest_match_date']))
                                <p class="text-[10px] text-stone-400 mt-0.5" title="First match — most recent match">
                                    {{ $stats['first_match_date']->format('M Y') }} – {{ $stats['latest_match_date']->format('M Y') }}
                                </p>
                            @endif
                        </div>
                    </div>
                @endif

                @php
                    $appearances = $nationalTeamAppearances ?? collect();
                @endphp

                {{-- Every year they shot for South Africa. Protea Colours
                     vs Federation colours is not shown until the awards
                     are confirmed from historical data. --}}
                @if($appearances->isNotEmpty())
                    <div class="mb-6">
                        <div class="flex items-baseline justify-between mb-3">
                            <h2 class="font-heading text-lg font-bold text-stone-900">Represented South Africa</h2>
                            <span class="text-xs text-stone-400">IPRF world championships &amp; national events</span>
                        </div>
                        <div class="rounded-xl border border-stone-200 bg-white overflow-hidden">
                            <ul class="divide-y divide-stone-100">
                                @foreach($appearances as $appearance)
                                    <li class="px-4 py-3 flex items-start gap-3">
                                        <span class="mt-0.5 inline-flex items-center justify-center size-8 rounded-full bg-stone-100 text-stone-700 text-xs font-bold tabular-nums shrink-0">{{ $appearance->year }}</span>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-stone-900 leading-tight">{{ $appearance->championship_name }}</p>
                                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-stone-500">
                                                @if($appearance->hostCountryName())
                                                    <span>{{ $appearance->hostCountryName() }}</span>
                                                @endif
                                                @if($appearance->divisionName())
                                                    <span class="text-stone-400">&middot;</span>
                                                    <span>{{ $appearance->divisionName() }}</span>
                                                @endif
                                                @if($appearance->placing)
                                                    @php
                                                        $sfx = match($appearance->placing % 10) {
                                                            1 => $appearance->placing === 11 ? 'th' : 'st',
                                                            2 => $appearance->placing === 12 ? 'th' : 'nd',
                                                            3 => $appearance->placing === 13 ? 'th' : 'rd',
                                                            default => 'th',
                                                        };
                                                    @endphp
                                                    <span class="text-stone-400">&middot;</span>
                                                    <span class="text-amber-700 font-semibold">{{ $appearance->placing }}{{ $sfx }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif

                {{-- Season switcher. Anchor links (full page load) rather than
                     an Alpine tab so each season is independently bookmarkable
                     and search-indexable. Active season carries the emerald
                     ring; the others are plain stone buttons. --}}
                @if($seasonsForSwitcher->isNotEmpty())
                    <div class="mb-6">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-stone-400 mb-2">Season</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach($seasonsForSwitcher as $s)
                                @php $isActive = (string) $s === (string) $season; @endphp
                                <a href="{{ route('shooters.show.season', ['saprfNumber' => $saprfNumber, 'season' => $s]) }}"
                                   class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-semibold transition {{ $isActive
                                        ? 'bg-emerald-600 text-white ring-2 ring-emerald-500 shadow-sm'
                                        : 'bg-white text-stone-600 ring-1 ring-inset ring-stone-200 hover:ring-stone-300 hover:text-stone-900' }}">
                                    {{ $s }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                @include('shooters._rifle-profile', ['profileRifles' => $profileRifles])

                @include('shooters._series-tabs', [
                    'seriesOrder' => $seriesOrder,
                    'summaryBySeries' => $summaryBySeries,
                    'scoresBySeries' => $scoresBySeries,
                ])
            </div>
        </div>

        @include('shooters._season-body')
    </div>

</x-layouts.public>
