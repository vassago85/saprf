@php
    $seoYear = $selectedYear ?? now()->year;
    $pageTitle = $selectedYear
        ? "Team South Africa {$selectedYear} — SAPRF"
        : 'The Proteas — Team South Africa — SAPRF';
    $pageDescription = $selectedYear
        ? "The South African team that represented the country at an IPRF World Precision Rifle Championship in {$selectedYear}."
        : 'Every SAPRF shooter who has worn the Protea at an IPRF World Precision Rifle Championship.';
    $ogImage = $selectedHeroCover?->variantUrl(1600, 'jpg')
        ?? $selectedHeroCover?->originalUrl()
        ?? null;
@endphp

<x-layouts.public
    :title="$pageTitle"
    :description="$pageDescription"
    :image="$ogImage"
    current="sa-team"
    :forcePublic="true">

    {{-- =====================================================================
         HERO — same footprint as the original fixed-height band, but
         object-contain so the full group fits (letterboxed, never cropped).
         Title + stats sit underneath so they don't cover anyone.
         ================================================================== --}}
    <section class="relative bg-stone-950 text-white overflow-hidden">
        <div class="relative h-[480px] sm:h-[560px] bg-stone-900">
            @if($selectedHeroCover)
                <x-media-picture
                    :item="$selectedHeroCover"
                    sizes="100vw"
                    class="absolute inset-0"
                    imgClass="absolute inset-0 w-full h-full object-contain object-center"
                    loading="eager"
                    :showCaption="false" />
            @else
                {{-- Neutral placeholder when the year has no cover photo yet --}}
                <div class="absolute inset-0 bg-gradient-to-br from-emerald-900 via-stone-900 to-stone-950 flex items-center justify-center">
                    <svg class="size-24 text-emerald-700/40" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/>
                    </svg>
                </div>
            @endif
        </div>

        <div class="relative border-t border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8 sm:py-10 flex flex-wrap items-end justify-between gap-6">
                <div class="max-w-2xl">
                    <span class="inline-block px-3 py-1.5 rounded-full bg-amber-400/15 text-amber-300 text-[11px] font-bold uppercase tracking-[0.15em]">
                        Team South Africa
                    </span>
                    <h1 class="mt-3 font-heading font-extrabold text-4xl sm:text-5xl lg:text-6xl leading-[0.95] text-white">
                        The Proteas
                    </h1>
                    <p class="mt-2 text-sm sm:text-base text-stone-300 leading-relaxed">
                        Every SAPRF shooter who has represented South Africa at an IPRF World Precision Rifle Championship.
                    </p>
                </div>

                <dl class="flex flex-wrap gap-3">
                    <div class="rounded-xl bg-white/10 border border-white/15 backdrop-blur px-5 py-3 min-w-[110px]">
                        <dt class="font-heading text-4xl font-extrabold tabular-nums">{{ $statUniqueProteas }}</dt>
                        <dd class="mt-1 text-[11px] font-semibold tracking-[0.12em] uppercase text-stone-300">Proteas</dd>
                    </div>
                    <div class="rounded-xl bg-white/10 border border-white/15 backdrop-blur px-5 py-3 min-w-[110px]">
                        <dt class="font-heading text-4xl font-extrabold tabular-nums">{{ $statAppearances }}</dt>
                        <dd class="mt-1 text-[11px] font-semibold tracking-[0.12em] uppercase text-stone-300">Appearances</dd>
                    </div>
                    <div class="rounded-xl bg-amber-400/15 border border-amber-400/40 backdrop-blur px-5 py-3 min-w-[110px]">
                        <dt class="font-heading text-4xl font-extrabold tabular-nums text-amber-300">{{ $statColours }}</dt>
                        <dd class="mt-1 text-[11px] font-semibold tracking-[0.12em] uppercase text-amber-200">Colours awarded</dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         YEAR SWITCHER — plain links so each year has a shareable URL
         ================================================================== --}}
    @if($yearsAvailable->count() > 0)
        <nav class="bg-white border-b border-stone-200" aria-label="Team year">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold text-stone-500 mr-2 uppercase tracking-wider">Team year</span>
                @foreach($yearsAvailable as $y)
                    @php $active = $y === $selectedYear; @endphp
                    <a href="{{ route('sa-team.year', ['year' => $y]) }}"
                       aria-current="{{ $active ? 'page' : 'false' }}"
                       class="inline-flex items-center min-h-[44px] px-5 rounded-full border text-sm font-semibold transition
                              {{ $active
                                 ? 'bg-emerald-700 border-emerald-700 text-white'
                                 : 'bg-white border-stone-300 text-stone-700 hover:border-emerald-600 hover:text-emerald-700' }}">
                        {{ $y }}
                    </a>
                @endforeach
            </div>
        </nav>
    @endif

    {{-- =====================================================================
         SELECTED YEAR — one block per championship (Centrefire / Rimfire)
         ================================================================== --}}
    @if($selectedYear === null)
        <section class="max-w-7xl mx-auto px-4 sm:px-6 py-16">
            <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-12 text-center">
                <h2 class="font-heading text-2xl font-bold text-stone-700">No teams recorded yet</h2>
                <p class="mt-2 text-sm text-stone-500 max-w-md mx-auto">
                    Once Exco records SA national-team appearances, every roster will appear here.
                </p>
            </div>
        </section>
    @else
        <main class="max-w-7xl mx-auto px-4 sm:px-6 py-10 sm:py-14 space-y-14">
            @foreach($selectedTeams as $block)
                @php
                    $team = $block['team'];
                    $captain = $block['captain'];
                    $manager = $block['manager'];
                @endphp

                <section>
                    {{-- Year head --}}
                    <div class="flex flex-wrap items-end justify-between gap-6 mb-7">
                        <div>
                            <div class="text-emerald-700 font-heading font-bold tracking-wider text-lg">{{ $selectedYear }}</div>
                            <h2 class="font-heading font-extrabold text-3xl sm:text-4xl text-stone-900 leading-tight mt-1">
                                {{ $block['championship'] }}
                            </h2>
                            <p class="text-sm text-stone-500 mt-1">
                                @if($team?->venue){{ $team->venue }}@endif
                                @if($team?->venue && ($team?->city || $block['host_country_name'])) · @endif
                                @if($team?->city){{ $team->city }}@endif
                                @if($team?->city && $block['host_country_name']), @endif
                                @if($block['host_country_name']){{ $block['host_country_name'] }}@endif
                                @if($team?->datesLabel())
                                    <span class="mx-1 text-stone-300">·</span>{{ $team->datesLabel() }}
                                @endif
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if($captain?->user)
                                <div class="bg-white border border-stone-200 rounded-xl px-4 py-3 min-w-[160px]">
                                    <div class="text-[10px] font-bold uppercase tracking-widest text-stone-500">Team captain</div>
                                    <div class="mt-0.5 font-semibold text-stone-900 text-sm">{{ $captain->user->name }}</div>
                                </div>
                            @endif
                            @if($manager?->user)
                                <div class="bg-white border border-stone-200 rounded-xl px-4 py-3 min-w-[160px]">
                                    <div class="text-[10px] font-bold uppercase tracking-widest text-stone-500">Team manager</div>
                                    <div class="mt-0.5 font-semibold text-stone-900 text-sm">{{ $manager->user->name }}</div>
                                </div>
                            @endif
                            <div class="bg-white border border-stone-200 rounded-xl px-4 py-3 min-w-[120px]">
                                <div class="text-[10px] font-bold uppercase tracking-widest text-stone-500">Squad</div>
                                <div class="mt-0.5 font-semibold text-stone-900 text-sm">{{ $block['squad_size'] }} {{ Str::plural('shooter', $block['squad_size']) }}</div>
                            </div>
                        </div>
                    </div>

                    {{-- Division cards --}}
                    @if(empty($block['divisions']))
                        <div class="rounded-2xl border border-dashed border-stone-300 bg-white p-10 text-center">
                            <p class="font-heading text-xl text-stone-500">Squad to be announced</p>
                        </div>
                    @else
                        <div class="grid gap-5" style="grid-template-columns: repeat(auto-fill, minmax(min(340px, 100%), 1fr));">
                            @foreach($block['divisions'] as $divCard)
                                <article class="bg-white border border-stone-200 rounded-2xl overflow-hidden shadow-sm">
                                    <header class="bg-emerald-950 text-white px-5 py-4 flex items-center justify-between">
                                        <h3 class="font-heading font-bold text-xl tracking-wide uppercase">{{ $divCard['name'] }}</h3>
                                        <span class="text-xs text-emerald-200">{{ $divCard['members']->count() }} shooters</span>
                                    </header>
                                    <ul class="divide-y divide-stone-100">
                                        @foreach($divCard['members'] as $appearance)
                                            @php
                                                $user = $appearance->user;
                                                $saprfNumber = $user?->membership?->saprf_number;
                                                $profileUrl = $saprfNumber
                                                    ? route('shooters.show', ['saprfNumber' => $saprfNumber])
                                                    : null;
                                                $initials = collect(explode(' ', (string) $user?->name))
                                                    ->filter(fn ($w) => $w !== '' && ctype_upper(substr($w, 0, 1)))
                                                    ->take(2)
                                                    ->map(fn ($w) => substr($w, 0, 1))
                                                    ->implode('');
                                                $isCaptain = $appearance->isCaptain();
                                            @endphp
                                            <li class="flex items-center gap-3.5 px-5 py-3">
                                                <div class="shrink-0 size-10 rounded-full bg-emerald-50 ring-1 ring-inset ring-emerald-200 text-emerald-700 flex items-center justify-center font-bold text-sm">
                                                    {{ $initials ?: 'SA' }}
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    @if($profileUrl)
                                                        <a href="{{ $profileUrl }}" class="block font-semibold text-stone-900 hover:text-emerald-700 truncate">
                                                            {{ $user?->name }}
                                                        </a>
                                                    @else
                                                        <span class="block font-semibold text-stone-900 truncate">{{ $user?->name ?? '—' }}</span>
                                                    @endif
                                                    @if($user?->province)
                                                        <span class="text-xs text-stone-500">{{ $user->province->name }}</span>
                                                    @endif
                                                </div>
                                                @if($isCaptain)
                                                    <span class="shrink-0 inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-900">Captain</span>
                                                @endif
                                                @if($appearance->awarded_colours)
                                                    <span class="shrink-0 inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800">Colours</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </main>
    @endif

    {{-- =====================================================================
         PAST TEAMS — grid of other years with cover + squad size
         ================================================================== --}}
    @if($pastTeams->isNotEmpty())
        <section class="max-w-7xl mx-auto px-4 sm:px-6 py-14">
            <h2 class="font-heading font-extrabold text-3xl sm:text-4xl text-stone-900">Past teams</h2>
            <p class="text-stone-500 mt-1 mb-6">Every South African team sent to an IPRF World Championship.</p>
            <div class="grid gap-5" style="grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr));">
                @foreach($pastTeams as $past)
                    @php $t = $past['team']; @endphp
                    <a href="{{ route('sa-team.year', ['year' => $t->year]) }}"
                       class="block bg-white border border-stone-200 rounded-2xl overflow-hidden hover:border-emerald-600 transition group">
                        <div class="h-40 bg-stone-800 relative">
                            @if($t->cover)
                                <x-media-picture :item="$t->cover" sizes="260px" class="h-40" imgClass="w-full h-40 object-cover" />
                            @else
                                <div class="w-full h-40 bg-gradient-to-br from-emerald-900 to-stone-900 flex items-center justify-center">
                                    <svg class="size-10 text-emerald-700/40" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/>
                                    </svg>
                                </div>
                            @endif
                        </div>
                        <div class="p-5">
                            <div class="font-heading font-extrabold text-3xl text-emerald-700 leading-none">{{ $t->year }}</div>
                            <div class="font-semibold text-stone-900 mt-2">{{ $t->championship_name }}</div>
                            <div class="text-sm text-stone-500 mt-1">
                                @if($t->city){{ $t->city }}@endif
                                @if($t->city && $past['squad_size']) · @endif
                                @if($past['squad_size']){{ $past['squad_size'] }} shooters @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- =====================================================================
         HONOUR ROLL — everyone, A to Z, with Alpine client-side search
         ================================================================== --}}
    @if($honourRoll->isNotEmpty())
        <section class="bg-emerald-950 text-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-14 sm:py-16"
                 x-data='{
                     q: "",
                     entries: @json($honourRoll),
                     get filtered() {
                         const q = this.q.trim().toLowerCase();
                         if (!q) return this.entries;
                         return this.entries.filter(e => e.name.toLowerCase().includes(q));
                     }
                 }'>
                <div class="flex flex-wrap items-end justify-between gap-6 mb-6">
                    <div>
                        <h2 class="font-heading font-extrabold text-3xl sm:text-4xl">Honour roll</h2>
                        <p class="text-stone-300 mt-1">All Proteas, A to Z, with the years they represented South Africa.</p>
                    </div>
                    <label class="flex flex-col gap-1.5 text-xs text-stone-300">
                        Find a shooter
                        <input type="search"
                               x-model="q"
                               placeholder="Search by name"
                               class="min-h-[44px] w-64 max-w-full px-3.5 rounded-lg bg-white/10 border border-white/20 text-white text-sm placeholder:text-stone-400 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </label>
                </div>

                <div class="grid gap-x-10" style="grid-template-columns: repeat(auto-fill, minmax(min(260px, 100%), 1fr));">
                    <template x-for="entry in filtered" :key="entry.name">
                        <div class="flex items-center justify-between gap-3 py-3 border-b border-white/10">
                            <template x-if="entry.profile_url">
                                <a :href="entry.profile_url" class="text-stone-100 hover:text-amber-300 truncate">
                                    <span x-text="entry.name"></span>
                                    <template x-if="entry.role_label">
                                        <span class="text-xs text-stone-400 ml-1">(<span x-text="entry.role_label"></span>)</span>
                                    </template>
                                </a>
                            </template>
                            <template x-if="!entry.profile_url">
                                <span class="text-stone-100 truncate">
                                    <span x-text="entry.name"></span>
                                    <template x-if="entry.role_label">
                                        <span class="text-xs text-stone-400 ml-1">(<span x-text="entry.role_label"></span>)</span>
                                    </template>
                                </span>
                            </template>
                            <b class="shrink-0 text-amber-300 text-sm font-semibold" x-text="entry.years_label"></b>
                        </div>
                    </template>
                </div>
            </div>
        </section>
    @endif
</x-layouts.public>
