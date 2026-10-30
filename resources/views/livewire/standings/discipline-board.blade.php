<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\NationalTeamAppearance;
use App\Models\Province;
use App\Models\Standing;
use App\Services\QualificationService;
use App\Services\SeasonMatchCounters;
use App\Services\SeasonStandingsBoard;
use App\Services\StandingsMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Series arrives from the route (/standings/prs or /standings/pr22)
     * via mount(). It is NEVER a #[Url] on this component because every
     * discipline has its own canonical URL — see routes/web.php.
     */
    public string $series = 'PRS';

    #[Url(except: '')]
    public string $season = '';

    #[Url(except: 'national')]
    public string $level = 'national';

    #[Url(as: 'division_id', except: '')]
    public string $divisionId = '';

    #[Url(as: 'province_id', except: '')]
    public string $provinceId = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: '')]
    public string $direction = '';

    /** When true, show every ranked shooter; otherwise truncate at $defaultLimit. */
    public bool $showAll = false;

    public int $defaultLimit = 10;

    public function mount(string $series = 'PRS'): void
    {
        $this->series = in_array($series, ['PRS', 'PR22'], true) ? $series : 'PRS';

        if ($this->season === '') {
            $this->season = (string) now()->year;
        }
        if (! in_array($this->level, ['national', 'provincial'], true)) {
            $this->level = 'national';
        }
    }

    public function setLevel(string $level): void
    {
        if (! in_array($level, ['national', 'provincial'], true)) {
            return;
        }
        $this->level = $level;
        $this->resetSort();
    }

    public function setDivision(int|string|null $divisionId = null): void
    {
        $divisionId = $divisionId === null ? '' : (string) $divisionId;
        $this->divisionId = ctype_digit($divisionId) ? $divisionId : '';
        $this->resetSort();
    }

    public function clearProvince(): void
    {
        $this->provinceId = '';
        $this->resetSort();
    }

    public function clearFilters(): void
    {
        $this->divisionId = '';
        $this->provinceId = '';
        $this->search = '';
        $this->resetSort();
    }

    public function updatedSeason(): void
    {
        $this->divisionId = '';
        $this->provinceId = '';
        $this->showAll = false;
        $this->resetSort();
    }

    public function updatedProvinceId(): void
    {
        $this->showAll = false;
        $this->resetSort();
    }

    public function updatedSearch(): void
    {
        // Any user-typed filter should re-expose the first page; otherwise
        // the "Show all" button appears stuck. We don't reset sort — a
        // user searching inside a specific sort order probably wants to
        // keep it.
        $this->showAll = false;
    }

    public function sortBy(string $column): void
    {
        $defaults = [
            'rank' => 'asc',
            'shooter' => 'asc',
            'division' => 'asc',
            'province' => 'asc',
            'points' => 'desc',
        ];
        if (! array_key_exists($column, $defaults)) {
            return;
        }

        [$currentSort, $currentDirection] = app(SeasonStandingsBoard::class)->normalizeSort(
            $this->sort !== '' ? $this->sort : null,
            $this->direction !== '' ? $this->direction : null,
        );

        $this->direction = $currentSort === $column
            ? ($currentDirection === 'asc' ? 'desc' : 'asc')
            : $defaults[$column];
        $this->sort = $column;
    }

    public function showEveryone(): void
    {
        $this->showAll = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function pageData(): array
    {
        $season = $this->season !== '' ? $this->season : (string) now()->year;
        $level = in_array($this->level, ['national', 'provincial'], true) ? $this->level : 'national';
        $divisionId = ctype_digit($this->divisionId) ? (int) $this->divisionId : null;
        $provinceId = ctype_digit($this->provinceId) ? (int) $this->provinceId : null;

        $page = app(SeasonStandingsBoard::class)->load(
            $season,
            $this->series,
            $level,
            $divisionId,
            $provinceId,
            $this->sort !== '' ? $this->sort : null,
            $this->direction !== '' ? $this->direction : null,
            // Null seed → deterministic surname/first-name/id order inside
            // ties. Same order the API and shooter profile use.
            null,
        );

        $standings = $page->standings;

        // Apply search last so it never affects ranking. Rank numbers
        // and T1 labels still come from the full ranked field above.
        $search = trim($this->search);
        if ($search !== '') {
            $needle = Str::lower($search);
            $standings = $standings->filter(fn (Standing $s) =>
                Str::contains(Str::lower((string) ($s->user?->name ?? '')), $needle)
            )->values();
        }

        $userIds = $standings->pluck('user_id')->filter()->unique()->values()->all();

        $seasons = Standing::query()->distinct()->pluck('season')->sort()->reverse()->values();
        if ($seasons->isEmpty()) {
            $seasons = collect([(string) now()->year]);
        }

        // Live + next-up strip. "Live" = today's or multi-day match
        // currently running. "Next" = the earliest upcoming match that is
        // not live, same series.
        $live = MatchEvent::query()
            ->where('match_type', $this->series)
            ->where('season', $season)
            ->published()
            ->live()
            ->with('province')
            ->orderBy('match_date')
            ->first();

        $next = MatchEvent::query()
            ->where('match_type', $this->series)
            ->where('season', $season)
            ->published()
            ->upcoming()
            ->when($live, fn ($q) => $q->where('id', '!=', $live->id))
            ->with('province')
            ->orderBy('match_date')
            ->first();

        $counters = app(SeasonMatchCounters::class)
            ->for($this->series, $season, $page->totalRanked);

        // Division leaders — top shooter(s) per division, used in the
        // sidebar card. The card is ordered by that top score, highest
        // first, so a division is not stuck in its catalogue position.
        // When several shooters share the winning total we keep all of
        // them so the sidebar can show the tie (T1 / T1).
        $divisionLeaders = Division::active()->ordered()->get()->map(function (Division $div) use ($season, $level, $provinceId) {
            $base = Standing::query()
                ->where('season', $season)
                ->where('series', $this->series)
                ->where('division_id', $div->id)
                ->when($level === 'provincial', fn ($q) => $q->whereNotNull('province_id'), fn ($q) => $q->whereNull('province_id'))
                ->when($provinceId && $level === 'provincial', fn ($q) => $q->where('province_id', $provinceId));

            $topPoints = (clone $base)->max('points');

            if ($topPoints === null) {
                return ['division' => $div, 'leaders' => collect()];
            }

            // Shooters tied on the top total (within half a thousandth —
            // Standing.points is cast float, same tolerance we use in
            // persistRankedStandings for competition ranking).
            $leaders = (clone $base)
                ->with(['user.province', 'division', 'province'])
                ->whereBetween('points', [$topPoints - 0.0005, $topPoints + 0.0005])
                ->orderBy('user_id')
                ->get();

            return [
                'division' => $div,
                'leaders' => $leaders,
            ];
        })->filter(fn (array $row) => $row['leaders']->isNotEmpty())
            ->sort(function (array $a, array $b): int {
                $byPoints = ((float) $b['leaders']->max('points')) <=> ((float) $a['leaders']->max('points'));
                if ($byPoints !== 0) {
                    return $byPoints;
                }

                return [(int) $a['division']->display_order, $a['division']->name]
                    <=> [(int) $b['division']->display_order, $b['division']->name];
            })
            ->values();

        // "Still to shoot" — upcoming matches in this series/season.
        $stillToShoot = MatchEvent::query()
            ->where('match_type', $this->series)
            ->where('season', $season)
            ->published()
            ->upcoming()
            ->with('province')
            ->orderBy('match_date')
            ->limit(5)
            ->get();

        // Movement arrows — one bulk service call keyed by user_id.
        $movement = app(StandingsMovement::class)
            ->for($userIds, $season, $this->series, $level, $provinceId, $divisionId);

        // Counting rule line + "/ max" header value.
        $qualifier = app(QualificationService::class);
        $countingRule = $qualifier->countingRuleText($this->series, $season, $level);
        $pointsMax = $qualifier->countingRuleMax($this->series, $season, $level);

        $lastUpdated = Standing::query()
            ->where('season', $season)
            ->where('series', $this->series)
            ->max('updated_at');

        return [
            'season' => $season,
            'level' => $level,
            'divisionId' => $divisionId,
            'provinceId' => $provinceId,
            'seasons' => $seasons,
            'divisions' => Division::active()->ordered()->get(),
            'provinces' => Province::orderBy('name')->get(),
            'standings' => $standings,
            'totalFiltered' => $standings->count(),
            'sort' => $page->sort,
            'direction' => $page->direction,
            'totalRanked' => $page->totalRanked,
            'showProvinceRank' => $page->showProvinceRank,
            'counters' => $counters,
            'countingRule' => $countingRule,
            'pointsMax' => $pointsMax,
            'live' => $live,
            'next' => $next,
            'divisionLeaders' => $divisionLeaders,
            'stillToShoot' => $stillToShoot,
            'movement' => $movement,
            'qualificationByUser' => $qualifier->bulkFinalsQualification($userIds, $this->series, $season),
            'saBadgesByUser' => NationalTeamAppearance::badgeDataForUsers($userIds),
            'lastUpdated' => $lastUpdated ? Carbon::parse($lastUpdated) : null,
        ];
    }

    private function resetSort(): void
    {
        $this->sort = '';
        $this->direction = '';
    }
}; ?>

@php
    $page = $this->pageData();

    $series = $this->series;
    $season = $page['season'];
    $level = $page['level'];
    $divisionId = $page['divisionId'];
    $provinceId = $page['provinceId'];
    $seasons = $page['seasons'];
    $divisions = $page['divisions'];
    $provinces = $page['provinces'];
    $standings = $page['standings'];
    $totalFiltered = $page['totalFiltered'];
    $counters = $page['counters'];
    $live = $page['live'];
    $next = $page['next'];
    $divisionLeaders = $page['divisionLeaders'];
    $stillToShoot = $page['stillToShoot'];
    $movement = $page['movement'];
    $qualificationByUser = $page['qualificationByUser'];
    $saBadgesByUser = $page['saBadgesByUser'];
    $lastUpdated = $page['lastUpdated'];
    $sort = $page['sort'];
    $direction = $page['direction'];
    $countingRule = $page['countingRule'];
    $pointsMax = $page['pointsMax'];

    // Podium = top 3 by competition rank (not by current table sort).
    $podium = $standings->sortBy(fn ($s) => (int) $s->rank)->take(3)->values();
    $leaderPoints = $podium->first() ? (float) $podium->first()->points : null;

    $isOverall = $divisionId === null;

    $disciplineLabel = $series === 'PRS' ? 'Centrefire' : 'Rimfire';
    $disciplineAccent = $series === 'PRS' ? 'text-emerald-700' : 'text-sky-700';
    $otherSeries = $series === 'PRS' ? 'PR22' : 'PRS';
    $otherSeriesHref = $otherSeries === 'PRS' ? route('standings.prs') : route('standings.pr22');

    // Visible slice honours "Show all". Don't collapse when the user has
    // actively filtered — the dataset is probably already short and
    // chopping it hides the point of the filter.
    $limit = $this->showAll || $this->search !== '' || $divisionId || $provinceId ? null : $this->defaultLimit;
    $visibleStandings = $limit === null ? $standings : $standings->take($limit);

    $sortableLinkBase = 'inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wider transition hover:text-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 rounded';
    $sortArrow = function (string $column) use ($sort, $direction) {
        if ($sort !== $column) {
            return '<span class="ml-0.5 text-stone-300" aria-hidden="true">↕</span>';
        }
        return $direction === 'asc'
            ? '<span class="ml-0.5 text-emerald-700" aria-hidden="true">↑</span>'
            : '<span class="ml-0.5 text-emerald-700" aria-hidden="true">↓</span>';
    };
@endphp

<div class="bg-stone-50 min-h-screen">
    {{-- Live / next-up strip. Only renders when there's something to say. --}}
    @if($live)
        <div class="bg-stone-900 text-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-6">
                <span class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider">
                    <span class="relative flex size-2">
                        <span class="absolute inset-0 rounded-full bg-red-400 opacity-75 animate-ping"></span>
                        <span class="relative size-2 rounded-full bg-red-500"></span>
                    </span>
                    Live now
                </span>
                <a href="{{ route('events.show', $live) }}" class="flex-1 text-sm sm:text-base font-semibold hover:underline">
                    {{ $live->name }}
                </a>
                <span class="text-xs text-stone-300">{{ $live->province?->name ?? '' }} &middot; {{ $live->match_date->format('j M') }}@if($live->match_end_date && $live->match_end_date->ne($live->match_date))–{{ $live->match_end_date->format('j M') }}@endif</span>
            </div>
        </div>
    @elseif($next)
        <div class="bg-emerald-50 border-b border-emerald-100">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-6">
                <span class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-800">
                    Next up
                </span>
                <a href="{{ route('events.show', $next) }}" class="flex-1 text-sm sm:text-base font-semibold text-stone-900 hover:text-emerald-800">
                    {{ $next->name }}
                </a>
                <span class="text-xs text-stone-600">{{ $next->province?->name ?? '' }} &middot; {{ $next->match_date->format('j M Y') }}</span>
            </div>
        </div>
    @endif

    {{-- Discipline switch + heading + counting rule. --}}
    <div class="bg-white border-b border-stone-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-8 sm:pt-10 pb-6">
            <nav aria-label="Discipline" class="mb-4">
                <div class="inline-flex rounded-xl bg-stone-100 p-1 border border-stone-200">
                    <a href="{{ route('standings.prs') }}"
                       aria-current="{{ $series === 'PRS' ? 'page' : 'false' }}"
                       class="px-5 py-2 rounded-lg text-sm font-semibold transition {{ $series === 'PRS' ? 'bg-white text-emerald-700 shadow-sm' : 'text-stone-500 hover:text-stone-900' }}">
                        PRS <span class="hidden sm:inline text-xs font-normal {{ $series === 'PRS' ? 'text-stone-500' : 'text-stone-400' }}">· Centrefire</span>
                    </a>
                    <a href="{{ route('standings.pr22') }}"
                       aria-current="{{ $series === 'PR22' ? 'page' : 'false' }}"
                       class="px-5 py-2 rounded-lg text-sm font-semibold transition {{ $series === 'PR22' ? 'bg-white text-sky-700 shadow-sm' : 'text-stone-500 hover:text-stone-900' }}">
                        PR22 <span class="hidden sm:inline text-xs font-normal {{ $series === 'PR22' ? 'text-stone-500' : 'text-stone-400' }}">· Rimfire</span>
                    </a>
                </div>
            </nav>

            <p class="text-xs font-bold uppercase tracking-widest {{ $disciplineAccent }}">
                {{ $disciplineLabel }} &middot; {{ $season }} season
            </p>
            {{-- font-extrabold (800) to match the live homepage hero's H1 weight —
                 the audit flagged that font-bold (700) read slightly lighter. --}}
            <h1 class="mt-2 font-heading text-4xl sm:text-5xl font-extrabold text-stone-900 tracking-tight">
                {{ $series }} standings
            </h1>
            <p class="mt-3 text-stone-600 max-w-3xl">
                {{ $countingRule }}
                @if($lastUpdated)
                    <span class="text-stone-400">&middot; Last updated {{ $lastUpdated->diffForHumans() }}</span>
                @endif
            </p>
        </div>
    </div>

    <x-sponsors-strip placement="leaderboard" class="bg-white border-b border-stone-200 !py-4" />

    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
        {{-- Counter cards. Five tiles — ranked, total, completed, in progress, remaining. --}}
        <h2 class="sr-only">Season counters</h2>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 sm:gap-4 mb-8">
            <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                <p class="font-heading text-3xl font-bold text-stone-900 tabular-nums">{{ $counters['ranked'] }}</p>
                <p class="mt-1 text-[11px] font-semibold uppercase tracking-wider text-stone-500">Ranked shooters</p>
            </div>
            <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                <p class="font-heading text-3xl font-bold text-stone-900 tabular-nums">{{ $counters['total'] }}</p>
                <p class="mt-1 text-[11px] font-semibold uppercase tracking-wider text-stone-500">Matches this season</p>
            </div>
            <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                <p class="font-heading text-3xl font-bold text-emerald-700 tabular-nums">{{ $counters['completed'] }}</p>
                <p class="mt-1 text-[11px] font-semibold uppercase tracking-wider text-stone-500">Completed</p>
            </div>
            <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                <p class="font-heading text-3xl font-bold text-amber-600 tabular-nums">{{ $counters['in_progress'] }}</p>
                <p class="mt-1 text-[11px] font-semibold uppercase tracking-wider text-stone-500">In progress</p>
            </div>
            <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                <p class="font-heading text-3xl font-bold text-stone-900 tabular-nums">{{ $counters['remaining'] }}</p>
                <p class="mt-1 text-[11px] font-semibold uppercase tracking-wider text-stone-500">Remaining</p>
            </div>
        </div>

        {{-- Podium. Only shown when the current scope has at least one ranked shooter. --}}
        @if($podium->isNotEmpty())
            <h2 class="sr-only">Podium</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-5 mb-8">
                @foreach($podium as $i => $standing)
                    @php
                        $rank = (int) $standing->rank;
                        $rankLabel = $standing->rank_label ?? (string) $rank;
                        $isLeader = $i === 0;
                        // "Level on points" fires when THIS shooter shares a
                        // rank with another (the T1/T2/… case). Previously
                        // the podium only looked for a tie WITH THE LEADER,
                        // which missed the common 1-leader + 2-tied-at-#2
                        // scenario the mockup is explicitly drawn around.
                        $isTiedRank = (bool) ($standing->is_tied ?? false);
                        $gap = $leaderPoints !== null && ! $isLeader ? $leaderPoints - (float) $standing->points : null;
                        $medalBg = $rank === 1 ? 'bg-amber-200 text-amber-900 ring-amber-300' : ($rank === 2 ? 'bg-stone-200 text-stone-700 ring-stone-300' : 'bg-orange-200 text-orange-900 ring-orange-300');
                        $saBadge = $saBadgesByUser[$standing->user_id] ?? null;
                        $userDiv = $standing->user?->division;
                        $prov = $standing->province ?? $standing->user?->province;
                    @endphp
                    <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-5 flex items-start gap-4 {{ $isLeader ? 'ring-2 ring-emerald-500/40' : '' }}">
                        <div class="shrink-0">
                            <span class="inline-flex items-center justify-center size-12 rounded-full {{ $medalBg }} ring-2 font-heading text-xl font-bold tabular-nums">
                                {{ $rankLabel }}
                            </span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <a href="{{ url('/standings/' . $season . '/shooter/' . ($standing->user_id ?? $standing->id)) }}"
                                   class="font-heading text-lg font-bold text-stone-900 hover:text-emerald-700 transition truncate">
                                    {{ $standing->user->name ?? '—' }}
                                </a>
                                @if($saBadge)
                                    <x-sa-team-badge :years="$saBadge['years']" :tooltip="$saBadge['tooltip']" compact />
                                @endif
                                @if(($standing->pool_breakdown['selection_eligible'] ?? true) === false)
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-700" title="{{ $standing->pool_breakdown['selection_note'] ?? '' }}">Not selection eligible</span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-xs text-stone-500">
                                {{ $userDiv?->name ?? '—' }} &middot; {{ $prov?->abbreviation ?? $prov?->name ?? '—' }}
                            </p>
                            <p class="mt-3 font-mono text-2xl font-bold text-stone-900 tabular-nums">
                                {{ points($standing->points) }}
                                <span class="text-sm font-semibold text-stone-400">/ {{ $pointsMax }}</span>
                            </p>
                            @if($isTiedRank)
                                <p class="mt-1 text-xs font-semibold text-emerald-700">Level on points</p>
                            @elseif($gap !== null && $gap > 0)
                                <p class="mt-1 text-xs text-stone-500">−{{ points($gap) }} to leader</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
            {{-- Main column: toolbar + table. --}}
            <section class="lg:col-span-2">
                <h2 class="font-heading text-xl font-bold text-stone-900 mb-4">Full rankings</h2>

                {{-- Toolbar: level toggle | division select | province select | search. --}}
                <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-3 sm:p-4 mb-4 flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex rounded-lg bg-stone-100 border border-stone-200 p-0.5">
                            <button type="button" wire:click="setLevel('national')"
                                    aria-pressed="{{ $level === 'national' ? 'true' : 'false' }}"
                                    class="px-3 py-1.5 rounded-md text-xs font-semibold transition {{ $level === 'national' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-900' }}">
                                National
                            </button>
                            <button type="button" wire:click="setLevel('provincial')"
                                    aria-pressed="{{ $level === 'provincial' ? 'true' : 'false' }}"
                                    class="px-3 py-1.5 rounded-md text-xs font-semibold transition {{ $level === 'provincial' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-900' }}">
                                Provincial
                            </button>
                        </div>

                        <div class="flex items-center gap-2">
                            <label for="standings_division" class="text-[11px] font-semibold uppercase tracking-wider text-stone-500">Division</label>
                            <select id="standings_division" wire:model.live="divisionId"
                                    class="rounded-lg border border-stone-300 bg-white text-xs py-1.5 pl-2.5 pr-7 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Overall</option>
                                @foreach($divisions as $div)
                                    <option value="{{ $div->id }}">{{ $div->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex items-center gap-2">
                            <label for="standings_province" class="text-[11px] font-semibold uppercase tracking-wider text-stone-500">Province</label>
                            <select id="standings_province" wire:model.live="provinceId"
                                    class="rounded-lg border border-stone-300 bg-white text-xs py-1.5 pl-2.5 pr-7 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">All</option>
                                @foreach($provinces as $prov)
                                    <option value="{{ $prov->id }}">{{ $prov->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex items-center gap-2 flex-1 sm:min-w-[220px]">
                            <label for="standings_search" class="sr-only">Search shooters</label>
                            <input type="search" id="standings_search" wire:model.live.debounce.300ms="search"
                                   placeholder="Search shooter…"
                                   class="w-full rounded-lg border border-stone-300 bg-white text-xs py-1.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>

                    @if($divisionId || $provinceId || $this->search !== '')
                        <div class="flex items-center gap-2 flex-wrap pt-1 border-t border-stone-100">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Filters</span>
                            @if($divisionId)
                                @php $activeDiv = $divisions->firstWhere('id', $divisionId); @endphp
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                                    {{ $activeDiv?->name ?? 'Division' }}
                                    <button type="button" wire:click="setDivision(null)" class="hover:text-amber-900" aria-label="Clear division">&times;</button>
                                </span>
                            @endif
                            @if($provinceId)
                                @php $activeProv = $provinces->firstWhere('id', $provinceId); @endphp
                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                                    {{ $activeProv?->name ?? 'Province' }}
                                    <button type="button" wire:click="clearProvince" class="hover:text-emerald-900" aria-label="Clear province">&times;</button>
                                </span>
                            @endif
                            @if($this->search !== '')
                                <span class="inline-flex items-center gap-1 rounded-full bg-stone-100 px-2 py-0.5 text-[11px] font-semibold text-stone-600 ring-1 ring-inset ring-stone-200">
                                    “{{ $this->search }}”
                                    <button type="button" wire:click="$set('search', '')" class="hover:text-stone-900" aria-label="Clear search">&times;</button>
                                </span>
                            @endif
                            <button type="button" wire:click="clearFilters" class="text-[11px] text-stone-400 hover:text-stone-600 transition">Clear all</button>
                        </div>
                    @endif
                </div>

                {{-- Table. --}}
                @if($visibleStandings->isEmpty())
                    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-10 text-center">
                        <h3 class="font-heading text-lg font-bold text-stone-700">No ranked shooters match these filters</h3>
                        <p class="mt-1 text-sm text-stone-500">Try clearing the filters or switching discipline.</p>
                        <button type="button" wire:click="clearFilters" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-emerald-700 hover:text-emerald-800">Clear filters</button>
                    </div>
                @else
                    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-stone-200 bg-stone-50/70">
                                        <th class="px-4 py-3 text-center w-14">
                                            <button type="button" wire:click="sortBy('rank')" class="{{ $sortableLinkBase }} {{ $sort === 'rank' ? 'text-stone-900' : 'text-stone-500' }}">
                                                Rank {!! $sortArrow('rank') !!}
                                            </button>
                                        </th>
                                        <th class="px-2 py-3 text-center w-10"><span class="sr-only">Movement</span></th>
                                        <th class="px-4 py-3 text-left">
                                            <button type="button" wire:click="sortBy('shooter')" class="{{ $sortableLinkBase }} {{ $sort === 'shooter' ? 'text-stone-900' : 'text-stone-500' }}">
                                                Shooter {!! $sortArrow('shooter') !!}
                                            </button>
                                        </th>
                                        <th class="px-4 py-3 text-left hidden sm:table-cell">
                                            <button type="button" wire:click="sortBy('division')" class="{{ $sortableLinkBase }} {{ $sort === 'division' ? 'text-stone-900' : 'text-stone-500' }}">
                                                Division {!! $sortArrow('division') !!}
                                            </button>
                                        </th>
                                        <th class="px-4 py-3 text-left hidden md:table-cell">
                                            <button type="button" wire:click="sortBy('province')" class="{{ $sortableLinkBase }} {{ $sort === 'province' ? 'text-stone-900' : 'text-stone-500' }}">
                                                Province {!! $sortArrow('province') !!}
                                            </button>
                                        </th>
                                        <th class="px-4 py-3 text-right">
                                            <button type="button" wire:click="sortBy('points')" class="{{ $sortableLinkBase }} {{ $sort === 'points' ? 'text-stone-900' : 'text-stone-500' }}">
                                                Points <span class="text-stone-400 font-normal normal-case">/ {{ $pointsMax }}</span> {!! $sortArrow('points') !!}
                                            </button>
                                        </th>
                                        <th class="px-4 py-3 text-right hidden md:table-cell">
                                            <span class="text-[11px] font-semibold uppercase tracking-wider text-stone-500">Gap</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($visibleStandings as $standing)
                                        @php
                                            $userDiv = $standing->user?->division;
                                            $prov = $standing->province ?? $standing->user?->province;
                                            $rankNumber = (int) $standing->rank;
                                            $rankLabel = $standing->rank_label ?? (string) $rankNumber;
                                            $saBadge = $saBadgesByUser[$standing->user_id] ?? null;
                                            $delta = $movement[$standing->user_id] ?? null;
                                            $q = $qualificationByUser[$standing->user_id] ?? null;
                                            $showQualifierFlag = $q && ($q['required'] ?? 0) > 0;
                                            $gap = $leaderPoints !== null ? $leaderPoints - (float) $standing->points : null;
                                            // Same rule as the podium: "Level" is a tie-RANK property,
                                            // not geometric distance to the leader. A shooter at rank
                                            // 2 who is tied with rank 2 (both T2) should read Level
                                            // even though they're 7 points behind the overall leader.
                                            $isTiedRank = (bool) ($standing->is_tied ?? false);
                                        @endphp
                                        <tr wire:key="standing-{{ $standing->id }}" class="border-b border-stone-100 transition hover:bg-stone-50/60 {{ $rankNumber <= 3 ? 'bg-emerald-50/20' : '' }}">
                                            <td class="px-4 py-4 text-center" data-rank-label="{{ $rankLabel }}">
                                                @if($rankNumber === 1)
                                                    <span class="inline-flex items-center justify-center min-w-8 h-8 px-1.5 rounded-full bg-amber-200 text-amber-900 text-sm font-bold tabular-nums shadow-sm">{{ $rankLabel }}</span>
                                                @elseif($rankNumber === 2)
                                                    <span class="inline-flex items-center justify-center min-w-8 h-8 px-1.5 rounded-full bg-stone-200 text-stone-700 text-sm font-bold tabular-nums">{{ $rankLabel }}</span>
                                                @elseif($rankNumber === 3)
                                                    <span class="inline-flex items-center justify-center min-w-8 h-8 px-1.5 rounded-full bg-orange-200 text-orange-900 text-sm font-bold tabular-nums">{{ $rankLabel }}</span>
                                                @else
                                                    <span class="text-sm font-semibold text-stone-500 tabular-nums">{{ $rankLabel }}</span>
                                                @endif
                                            </td>
                                            <td class="px-2 py-4 text-center">
                                                @if($delta === null)
                                                    <span class="text-stone-300 text-sm" title="No previous snapshot">·</span>
                                                @elseif($delta > 0)
                                                    <span class="inline-flex items-center text-xs font-bold text-emerald-700 tabular-nums" title="Up {{ $delta }} since last match">▲{{ $delta }}</span>
                                                @elseif($delta < 0)
                                                    <span class="inline-flex items-center text-xs font-bold text-amber-700 tabular-nums" title="Down {{ abs($delta) }} since last match">▼{{ abs($delta) }}</span>
                                                @else
                                                    <span class="text-stone-400 text-sm" title="No change since last match">–</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-4">
                                                <span class="inline-flex items-center gap-1.5 flex-wrap">
                                                    <a href="{{ url('/standings/' . $season . '/shooter/' . ($standing->user_id ?? $standing->id)) }}"
                                                       class="text-sm font-semibold text-stone-900 hover:text-emerald-700 transition">
                                                        {{ $standing->user->name ?? '—' }}
                                                    </a>
                                                    @if($saBadge)
                                                        <x-sa-team-badge :years="$saBadge['years']" :tooltip="$saBadge['tooltip']" compact />
                                                    @endif
                                                    @if(($standing->pool_breakdown['selection_eligible'] ?? true) === false)
                                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-700" title="{{ $standing->pool_breakdown['selection_note'] ?? '' }}">Not selection eligible</span>
                                                    @endif
                                                    @if($showQualifierFlag)
                                                        @if($q['qualified'])
                                                            <span class="inline-flex items-center justify-center size-4 rounded-full bg-emerald-100 text-emerald-700"
                                                                  title="Finale-eligible — {{ $q['completed'] }} / {{ $q['required'] }} out-of-province nationals shot"
                                                                  aria-label="Finale eligible">
                                                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                                            </span>
                                                        @else
                                                            <span class="inline-flex items-center justify-center size-4 rounded-full ring-1 ring-stone-300 text-stone-400"
                                                                  title="Needs {{ $q['remaining'] }} more OOP national{{ $q['remaining'] === 1 ? '' : 's' }} ({{ $q['completed'] }} / {{ $q['required'] }})"
                                                                  aria-label="Not yet finale eligible">
                                                                <svg class="size-2.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2" /></svg>
                                                            </span>
                                                        @endif
                                                    @endif
                                                </span>
                                                <span class="sm:hidden block text-xs text-stone-400 mt-0.5">
                                                    {{ $userDiv?->name ?? '—' }} &middot; {{ $prov?->abbreviation ?? $prov?->name ?? '—' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-4 hidden sm:table-cell">
                                                <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">
                                                    {{ $userDiv?->name ?? '—' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-4 hidden md:table-cell">
                                                <span class="inline-flex items-center gap-1 rounded-md bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-700">
                                                    {{ $prov?->abbreviation ?? $prov?->name ?? '—' }}
                                                    @if($standing->province_rank_label)
                                                        <span class="font-semibold text-stone-500" data-province-rank="{{ $standing->province_rank_label }}">{{ $standing->province_rank_label }}</span>
                                                    @endif
                                                </span>
                                            </td>
                                            <td class="px-4 py-4 text-right">
                                                <span class="font-mono text-sm font-bold text-stone-900 tabular-nums">{{ points($standing->points) }}</span>
                                            </td>
                                            <td class="px-4 py-4 text-right hidden md:table-cell">
                                                @if($isTiedRank)
                                                    <span class="text-[11px] font-semibold text-emerald-700">Level</span>
                                                @elseif($gap !== null && $gap > 0)
                                                    <span class="font-mono text-xs text-stone-500 tabular-nums">−{{ points($gap) }}</span>
                                                @else
                                                    <span class="text-stone-300">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if($limit !== null && $standings->count() > $limit)
                            <div class="border-t border-stone-100 px-4 py-3 text-center">
                                <button type="button" wire:click="showEveryone" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800 transition">
                                    Show all {{ $standings->count() }} shooters
                                </button>
                            </div>
                        @endif
                    </div>
                @endif
            </section>

            {{-- Sidebar: division leaders | how ranking works | still to shoot. --}}
            <aside class="space-y-6">
                <section class="bg-white rounded-xl border border-stone-200 shadow-sm p-5">
                    <h2 class="font-heading text-base font-bold text-stone-900 mb-3">Division leaders</h2>
                    @if($divisionLeaders->isEmpty())
                        <p class="text-xs text-stone-500">No division leaders yet.</p>
                    @else
                        <ul class="divide-y divide-stone-100">
                            @foreach($divisionLeaders as $row)
                                @php
                                    $div = $row['division'];
                                    $leaders = $row['leaders'];
                                    $tied = $leaders->count() > 1;
                                @endphp
                                <li class="py-2.5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-amber-700 mb-1">{{ $div->name }}</p>
                                    @foreach($leaders as $leader)
                                        <div class="flex items-center justify-between gap-3 {{ !$loop->first ? 'mt-1' : '' }}">
                                            <div class="min-w-0 flex-1 flex items-center gap-2">
                                                @if($tied)
                                                    <span class="inline-flex items-center justify-center min-w-[1.5rem] h-5 px-1.5 rounded-md bg-emerald-50 text-[10px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200 shrink-0"
                                                          title="Tied — share rank">T1</span>
                                                @endif
                                                <a href="{{ url('/standings/' . $season . '/shooter/' . $leader->user_id) }}"
                                                   class="text-sm font-semibold text-stone-900 hover:text-emerald-700 transition truncate block">
                                                    {{ $leader->user?->name ?? '—' }}
                                                </a>
                                            </div>
                                            @if($loop->first)
                                                <span class="font-mono text-sm font-bold text-stone-900 tabular-nums shrink-0">{{ points($leader->points) }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="bg-white rounded-xl border border-stone-200 shadow-sm p-5">
                    <h2 class="font-heading text-base font-bold text-stone-900 mb-3">How ranking works</h2>
                    <p class="text-sm text-stone-600 leading-relaxed">{{ $countingRule }}</p>
                    <ul class="mt-3 space-y-1.5 text-xs text-stone-500">
                        <li class="flex gap-2"><span class="text-emerald-700">▲</span> Rank up since last match</li>
                        <li class="flex gap-2"><span class="text-amber-700">▼</span> Rank down since last match</li>
                        <li class="flex gap-2"><span class="text-stone-400">–</span> No change</li>
                        <li class="flex gap-2"><span class="font-bold text-emerald-700">T1</span> Tied on points — share rank</li>
                    </ul>
                    <a href="{{ url('/rules') }}" class="mt-4 inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 hover:text-emerald-800">
                        Full ranking rules
                        <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </section>

                <section class="bg-white rounded-xl border border-stone-200 shadow-sm p-5">
                    <h2 class="font-heading text-base font-bold text-stone-900 mb-3">Still to shoot</h2>
                    @if($stillToShoot->isEmpty())
                        <p class="text-xs text-stone-500">No scheduled matches remaining for this season.</p>
                    @else
                        <ul class="divide-y divide-stone-100">
                            @foreach($stillToShoot as $match)
                                <li class="py-2.5">
                                    <a href="{{ route('events.show', $match) }}" class="block group">
                                        <p class="text-sm font-semibold text-stone-900 group-hover:text-emerald-700 transition">{{ $match->name }}</p>
                                        <p class="text-[11px] text-stone-500 mt-0.5">{{ $match->match_date->format('j M Y') }} &middot; {{ $match->province?->name ?? '' }}</p>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </aside>
        </div>
    </div>
</div>
