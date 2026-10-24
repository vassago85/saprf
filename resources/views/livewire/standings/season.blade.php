<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\NationalTeamAppearance;
use App\Models\Province;
use App\Models\Standing;
use App\Services\QualificationService;
use App\Services\SeasonStandingsBoard;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component
{
    #[Url(except: '')]
    public string $season = '';

    #[Url(except: 'PRS')]
    public string $series = 'PRS';

    #[Url(except: 'national')]
    public string $level = 'national';

    #[Url(as: 'division_id', except: '')]
    public string $divisionId = '';

    #[Url(as: 'province_id', except: '')]
    public string $provinceId = '';

    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: '')]
    public string $direction = '';

    public function mount(): void
    {
        if ($this->season === '') {
            $this->season = (string) now()->year;
        }
        if (! in_array($this->series, ['PRS', 'PR22'], true)) {
            $this->series = 'PRS';
        }
        if (! in_array($this->level, ['national', 'provincial'], true)) {
            $this->level = 'national';
        }
    }

    public function setSeries(string $series): void
    {
        if (! in_array($series, ['PRS', 'PR22'], true)) {
            return;
        }

        $this->series = $series;
        $this->divisionId = '';
        $this->provinceId = '';
        $this->resetSort();
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
        $this->resetSort();
    }

    public function updatedSeason(): void
    {
        $this->divisionId = '';
        $this->provinceId = '';
        $this->resetSort();
    }

    public function updatedProvinceId(): void
    {
        $this->resetSort();
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

    /**
     * @return array<string, mixed>
     */
    public function pageData(): array
    {
        $season = $this->season !== '' ? $this->season : (string) now()->year;
        $series = in_array($this->series, ['PRS', 'PR22'], true) ? $this->series : 'PRS';
        $level = in_array($this->level, ['national', 'provincial'], true) ? $this->level : 'national';
        $divisionId = ctype_digit($this->divisionId) ? (int) $this->divisionId : null;
        $provinceId = ctype_digit($this->provinceId) ? (int) $this->provinceId : null;

        $page = app(SeasonStandingsBoard::class)->load(
            $season,
            $series,
            $level,
            $divisionId,
            $provinceId,
            $this->sort !== '' ? $this->sort : null,
            $this->direction !== '' ? $this->direction : null,
            // Null seed → deterministic surname/first-name/id order inside
            // ties. Keeps the display order stable across visits AND identical
            // to what the homepage API returns via the same ranker.
            null,
        );

        $userIds = $page->standings->pluck('user_id')->filter()->unique()->values()->all();

        $seasons = Standing::query()->distinct()->pluck('season')->sort()->reverse()->values();
        if ($seasons->isEmpty()) {
            $seasons = collect([(string) now()->year]);
        }

        return [
            'season' => $season,
            'series' => $series,
            'level' => $level,
            'divisionId' => $divisionId,
            'provinceId' => $provinceId,
            'seasons' => $seasons,
            'divisions' => Division::active()->ordered()->get(),
            'provinces' => Province::orderBy('name')->get(),
            'standings' => $page->standings,
            'sort' => $page->sort,
            'direction' => $page->direction,
            'totalRanked' => $page->totalRanked,
            'showProvinceRank' => $page->showProvinceRank,
            'totalMatches' => MatchEvent::where('season', $season)->where('match_type', $series)->published()->count(),
            'completedMatches' => MatchEvent::where('season', $season)->where('match_type', $series)->where('status', 'completed')->count(),
            'remainingMatches' => MatchEvent::where('season', $season)->where('match_type', $series)
                ->where('match_date', '>=', now()->startOfDay())
                ->whereIn('status', ['open', 'closed', 'draft'])->count(),
            'qualificationByUser' => app(QualificationService::class)->bulkFinalsQualification($userIds, $series, $season),
            'saBadgesByUser' => NationalTeamAppearance::badgeDataForUsers($userIds),
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
    $season = $page['season'];
    $series = $page['series'];
    $level = $page['level'];
    $divisionId = $page['divisionId'];
    $provinceId = $page['provinceId'];
    $seasons = $page['seasons'];
    $divisions = $page['divisions'];
    $provinces = $page['provinces'];
    $standings = $page['standings'];
    $sort = $page['sort'];
    $direction = $page['direction'];
    $isOverall = $divisionId === null;
    $baseParams = array_filter([
        'season' => $season,
        'series' => $series,
        'level' => $level,
    ]);
    $filterParams = array_filter([
        'season' => $season,
        'series' => $series,
        'level' => $level,
        'division_id' => $divisionId,
        'province_id' => $provinceId,
    ]);
@endphp

<div class="bg-stone-50 min-h-screen">
    <div class="bg-white border-b border-stone-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <h1 class="font-heading text-3xl sm:text-4xl font-bold text-stone-900 tracking-tight">
                Season Standings
                <span class="ml-2 text-base font-semibold uppercase tracking-wider {{ $series === 'PRS' ? 'text-emerald-700' : 'text-sky-700' }} align-middle">{{ $series }}</span>
                <span class="ml-1 text-base font-semibold uppercase tracking-wider {{ $level === 'provincial' ? 'text-blue-700' : 'text-stone-700' }} align-middle">&middot; {{ $level === 'provincial' ? 'Provincial' : 'National' }}</span>
            </h1>
            <p class="mt-1.5 text-stone-500">
                Official <span class="font-semibold text-stone-700">{{ $series }} {{ $level === 'provincial' ? 'Provincial' : 'National' }}</span> rankings for the {{ $season }} season.
            </p>
        </div>
    </div>

    <x-sponsors-strip placement="leaderboard" class="bg-white border-b border-stone-200 !py-4" />

    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
        <h2 class="sr-only">Filters</h2>
        <div class="flex flex-col sm:flex-row sm:items-center gap-4 mb-4">
            <div class="flex items-center gap-2">
                <label for="standings_season" class="sr-only">Season</label>
                <select id="standings_season" wire:model.live="season"
                        class="rounded-xl border border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                    @foreach($seasons as $s)
                        <option value="{{ $s }}">{{ $s }} Season</option>
                    @endforeach
                </select>
            </div>

            <div class="flex rounded-xl bg-white border border-stone-200 shadow-sm p-1 w-fit">
                <a href="{{ url('/standings') . '?' . http_build_query(array_merge($baseParams, ['series' => 'PRS'])) }}"
                   wire:click.prevent="setSeries('PRS')"
                   class="px-5 py-2 rounded-lg text-sm font-semibold transition {{ $series === 'PRS' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                    PRS
                </a>
                <a href="{{ url('/standings') . '?' . http_build_query(array_merge($baseParams, ['series' => 'PR22'])) }}"
                   wire:click.prevent="setSeries('PR22')"
                   class="px-5 py-2 rounded-lg text-sm font-semibold transition {{ $series === 'PR22' ? 'bg-sky-600 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                    PR22
                </a>
            </div>

            <div class="flex rounded-xl bg-white border border-stone-200 shadow-sm p-1 w-fit">
                <a href="{{ url('/standings') . '?' . http_build_query(array_merge($filterParams, ['level' => 'national'])) }}"
                   wire:click.prevent="setLevel('national')"
                   class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $level === 'national' ? 'bg-stone-900 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                    National
                </a>
                <a href="{{ url('/standings') . '?' . http_build_query(array_merge($filterParams, ['level' => 'provincial'])) }}"
                   wire:click.prevent="setLevel('provincial')"
                   class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $level === 'provincial' ? 'bg-stone-900 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                    Provincial
                </a>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-start gap-4 mb-6">
            <div class="flex flex-wrap rounded-xl bg-white border border-stone-200 shadow-sm p-1 gap-0.5">
                <a href="{{ url('/standings') . '?' . http_build_query(array_filter(array_merge($baseParams, ['province_id' => $provinceId]))) }}"
                   wire:click.prevent="setDivision(null)"
                   class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $isOverall ? 'bg-amber-500 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                    Overall
                </a>
                @foreach($divisions as $div)
                    <a href="{{ url('/standings') . '?' . http_build_query(array_filter(array_merge($baseParams, ['division_id' => $div->id, 'province_id' => $provinceId]))) }}"
                       wire:click.prevent="setDivision({{ $div->id }})"
                       class="px-4 py-1.5 rounded-lg text-xs font-semibold transition {{ $divisionId === $div->id ? 'bg-amber-500 text-white shadow-sm' : 'text-stone-500 hover:text-stone-700 hover:bg-stone-50' }}">
                        {{ $div->name }}
                    </a>
                @endforeach
            </div>

            <div class="flex items-center gap-2">
                <label for="standings_province" class="sr-only">Province</label>
                <select id="standings_province" wire:model.live="provinceId"
                        class="rounded-xl border border-stone-300 bg-white text-sm py-2 pl-3 pr-8 focus:ring-emerald-500 focus:border-emerald-500 shadow-sm">
                    <option value="">All Provinces</option>
                    @foreach($provinces as $prov)
                        <option value="{{ $prov->id }}">{{ $prov->name }}</option>
                    @endforeach
                </select>
            </div>

            @if($divisionId || $provinceId)
                <div class="flex items-center gap-2 flex-wrap">
                    @if($divisionId)
                        @php $activeDiv = $divisions->firstWhere('id', $divisionId); @endphp
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                            {{ $activeDiv?->name ?? 'Division' }}
                            <a href="{{ url('/standings') . '?' . http_build_query(array_filter(array_merge($baseParams, ['province_id' => $provinceId]))) }}"
                               wire:click.prevent="setDivision(null)"
                               class="ml-0.5 hover:text-amber-900">&times;</a>
                        </span>
                    @endif
                    @if($provinceId)
                        @php $activeProvince = $provinces->firstWhere('id', $provinceId); @endphp
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                            {{ $activeProvince?->name ?? 'Province' }}
                            <a href="{{ url('/standings') . '?' . http_build_query(array_filter(array_merge($baseParams, ['division_id' => $divisionId]))) }}"
                               wire:click.prevent="clearProvince"
                               class="ml-0.5 hover:text-emerald-900">&times;</a>
                        </span>
                    @endif
                    <a href="{{ url('/standings') . '?' . http_build_query($baseParams) }}"
                       wire:click.prevent="clearFilters"
                       class="text-xs text-stone-400 hover:text-stone-600 transition">Clear all</a>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                <p class="text-2xl font-bold text-stone-900">{{ $page['totalRanked'] }}</p>
                <p class="text-xs text-stone-400 mt-0.5">Ranked Shooters</p>
            </div>
            <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                <p class="text-2xl font-bold text-stone-900">{{ $page['totalMatches'] }}</p>
                <p class="text-xs text-stone-400 mt-0.5">Matches This Season</p>
            </div>
            <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                <p class="text-2xl font-bold text-stone-900">{{ $page['completedMatches'] }}</p>
                <p class="text-xs text-stone-400 mt-0.5">Completed</p>
            </div>
            <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-4">
                <p class="text-2xl font-bold text-stone-900">{{ $page['remainingMatches'] }}</p>
                <p class="text-xs text-stone-400 mt-0.5">Remaining</p>
            </div>
        </div>

        <h2 class="sr-only">Standings</h2>
        @include('standings._public-table', [
            'standings' => $standings,
            'qualificationByUser' => $page['qualificationByUser'],
            'saBadgesByUser' => $page['saBadgesByUser'],
            'showProvince' => true,
            'showDivision' => true,
            'series' => $series,
            'season' => $season,
            'sort' => $sort,
            'direction' => $direction,
            'filterParams' => $filterParams,
        ])
    </div>
</div>
