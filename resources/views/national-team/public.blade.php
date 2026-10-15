<x-layouts.public
    title="Represented South Africa — SAPRF"
    description="Every shooter who has represented South Africa at the IPRF World Precision Rifle Championships. Protea Colours holders and SA national-team roster, year by year."
    current="sa-team">

    <div class="bg-stone-50 min-h-screen">
        {{-- Page Header --}}
        <div class="bg-gradient-to-br from-emerald-700 via-emerald-800 to-stone-900 border-b border-stone-200">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-10 sm:py-14">
                <div class="flex items-start gap-4">
                    <svg class="size-10 shrink-0 text-amber-300 mt-1" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/>
                    </svg>
                    <div>
                        <h1 class="font-heading text-3xl sm:text-4xl font-bold text-white tracking-tight">
                            Represented South Africa
                        </h1>
                        <p class="mt-2 text-emerald-100 max-w-2xl">
                            Every SAPRF shooter who has worn the Protea at an IPRF World Precision Rifle Championship.
                            Honouring the athletes who have carried South Africa onto the world stage.
                        </p>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-3 gap-3 sm:gap-6 max-w-xl">
                    <div class="rounded-xl bg-white/10 backdrop-blur px-4 py-3">
                        <p class="text-2xl sm:text-3xl font-bold text-white tabular-nums">{{ $uniqueShooters }}</p>
                        <p class="text-[11px] uppercase tracking-wider text-emerald-200 mt-0.5">Proteas</p>
                    </div>
                    <div class="rounded-xl bg-white/10 backdrop-blur px-4 py-3">
                        <p class="text-2xl sm:text-3xl font-bold text-white tabular-nums">{{ $totalAppearances }}</p>
                        <p class="text-[11px] uppercase tracking-wider text-emerald-200 mt-0.5">Appearances</p>
                    </div>
                    <div class="rounded-xl bg-white/10 backdrop-blur px-4 py-3">
                        <p class="text-2xl sm:text-3xl font-bold text-amber-300 tabular-nums">{{ $coloursHolders }}</p>
                        <p class="text-[11px] uppercase tracking-wider text-emerald-200 mt-0.5">Colours</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            @if($groupedByYear->isEmpty())
                <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-12 text-center">
                    <svg class="mx-auto size-12 text-stone-300 mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5V18a2.25 2.25 0 0 0 2.25 2.25h13.5A2.25 2.25 0 0 0 21 18V7.5m-18 0 9-4.5 9 4.5m-18 0 9 4.5m9-4.5-9 4.5m0 0v12"/>
                    </svg>
                    <h2 class="text-lg font-semibold text-stone-700">No appearances recorded yet</h2>
                    <p class="mt-1 text-sm text-stone-500 max-w-md mx-auto">
                        Once Exco records SA national-team appearances, this page will list every shooter who has represented South Africa, year by year.
                    </p>
                </div>
            @else
                {{-- Year quick-jump nav. Each year section has an id so these
                     anchor links deep-link into a specific championship year. --}}
                @if($yearsCovered->count() > 1)
                    <div class="mb-6 flex flex-wrap items-center gap-2 bg-white rounded-xl border border-stone-200 shadow-sm px-4 py-3">
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Jump to</span>
                        @foreach($yearsCovered as $y)
                            <a href="#year-{{ $y }}"
                               class="inline-flex items-center rounded-lg bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-700 hover:bg-emerald-100 hover:text-emerald-800 transition">
                                {{ $y }}
                            </a>
                        @endforeach
                    </div>
                @endif

                <div class="space-y-10">
                    @foreach($groupedByYear as $year => $yearAppearances)
                        @php
                            // All appearances share the same championship_name
                            // in practice (one team per year), but defensive
                            // in case Exco records multiple events in one year.
                            $byChampionship = $yearAppearances->groupBy('championship_name');
                        @endphp

                        <section id="year-{{ $year }}" class="scroll-mt-20">
                            <div class="flex items-baseline justify-between mb-4">
                                <h2 class="font-heading text-2xl sm:text-3xl font-bold text-stone-900 tracking-tight">
                                    {{ $year }}
                                </h2>
                                <span class="text-xs text-stone-400">
                                    {{ $yearAppearances->count() }} {{ Str::plural('member', $yearAppearances->count()) }}
                                </span>
                            </div>

                            @foreach($byChampionship as $championshipName => $champAppearances)
                                @php
                                    $first = $champAppearances->first();
                                @endphp
                                <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden mb-5">
                                    <div class="bg-stone-50/50 border-b border-stone-100 px-5 py-3 flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <h3 class="font-semibold text-stone-900">{{ $championshipName }}</h3>
                                            @if($first?->hostCountryName())
                                                <p class="text-xs text-stone-500 mt-0.5 inline-flex items-center gap-1">
                                                    <svg class="size-3 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                                    {{ $first->hostCountryName() }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>

                                    <ul class="divide-y divide-stone-100">
                                        @foreach($champAppearances->sortBy(fn ($a) => strtolower((string) ($a->user?->name ?? ''))) as $appearance)
                                            @php
                                                $user = $appearance->user;
                                                $saprfNumber = $user?->membership?->saprf_number;
                                                $profileUrl = $saprfNumber
                                                    ? route('shooters.show', ['saprfNumber' => $saprfNumber])
                                                    : null;
                                                $divisionLabel = $appearance->division_label ?: $appearance->divisionName();
                                                $isManager = strcasecmp((string) $divisionLabel, 'Team Manager') === 0;
                                            @endphp
                                            <li class="px-5 py-3 flex items-center gap-3 hover:bg-stone-50/50 transition">
                                                <div class="shrink-0 size-9 rounded-full bg-gradient-to-br from-emerald-100 to-amber-100 ring-1 ring-inset ring-emerald-200 flex items-center justify-center">
                                                    @if($appearance->awarded_colours)
                                                        <svg class="size-4 text-emerald-700" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true" title="Awarded Protea Colours">
                                                            <path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/>
                                                        </svg>
                                                    @else
                                                        <span class="text-[10px] font-bold text-emerald-700">SA</span>
                                                    @endif
                                                </div>

                                                <div class="flex-1 min-w-0">
                                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                        @if($profileUrl)
                                                            <a href="{{ $profileUrl }}" class="text-sm font-semibold text-stone-900 hover:text-emerald-700 transition">
                                                                {{ $user?->name ?? '—' }}
                                                            </a>
                                                        @else
                                                            <span class="text-sm font-semibold text-stone-900">{{ $user?->name ?? '—' }}</span>
                                                        @endif

                                                        @if($isManager)
                                                            <span class="inline-flex items-center rounded-md bg-stone-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-stone-600 ring-1 ring-inset ring-stone-200">
                                                                Team Manager
                                                            </span>
                                                        @elseif($divisionLabel)
                                                            <span class="inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-amber-700 ring-1 ring-inset ring-amber-200">
                                                                {{ $divisionLabel }}
                                                            </span>
                                                        @endif

                                                        @if($appearance->awarded_colours)
                                                            <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-emerald-700 ring-1 ring-inset ring-emerald-200" title="This appearance granted Protea Colours">
                                                                <svg class="size-2.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/></svg>
                                                                Colours
                                                            </span>
                                                        @endif
                                                    </div>

                                                    @if($user?->province || $appearance->placing)
                                                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-stone-500">
                                                            @if($user?->province)
                                                                <span>{{ $user->province->name }}</span>
                                                            @endif
                                                            @if($user?->province && $appearance->placing)
                                                                <span class="text-stone-300">·</span>
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
                                                                <span class="text-amber-700 font-semibold">Finished {{ $appearance->placing }}{{ $sfx }}</span>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts.public>
