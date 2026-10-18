<?php

namespace App\Livewire\Dashboard;

use App\Models\RifleConfiguration;
use App\Models\Score;
use App\Models\User;
use App\Support\Dashboard\Shooter\AttentionBuilder;
use App\Support\Dashboard\Shooter\DisciplineScope;
use App\Support\Dashboard\Shooter\HeroStandingBuilder;
use App\Support\Dashboard\Shooter\MembershipStripBuilder;
use App\Support\Dashboard\Shooter\NextMatchesBuilder;
use App\Support\Dashboard\Shooter\RifleDopeBuilder;
use App\Support\Dashboard\Shooter\SeasonCardBuilder;
use App\Support\Dashboard\Shooter\SeasonKpiBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class ShooterDashboard extends Component
{
    #[Url(as: 'nextFilter', except: 'all')]
    public string $nextFilter = 'all';

    #[Url(as: 'resultsFilter', except: 'all')]
    public string $resultsFilter = 'all';

    /**
     * Which discipline the dark hero panel shows standings for. Only
     * meaningful for dual-discipline members; forced to the single
     * active discipline otherwise.
     */
    #[Url(as: 'hero', except: '')]
    public string $heroDiscipline = '';

    public function setNextFilter(string $filter): void
    {
        $this->nextFilter = $this->normalizeFilter($filter);
    }

    public function setResultsFilter(string $filter): void
    {
        $this->resultsFilter = $this->normalizeFilter($filter);
    }

    public function setHeroDiscipline(string $discipline): void
    {
        $discipline = strtoupper($discipline);
        $this->heroDiscipline = in_array($discipline, ['PRS', 'PR22'], true) ? $discipline : '';
    }

    public function render(
        DisciplineScope $scope,
        NextMatchesBuilder $nextMatches,
        AttentionBuilder $attention,
        SeasonCardBuilder $seasonCards,
        HeroStandingBuilder $hero,
        SeasonKpiBuilder $kpis,
        RifleDopeBuilder $dope,
        MembershipStripBuilder $membershipStrip,
    ) {
        /** @var User $user */
        $user = Auth::user();
        $user->loadMissing(['membership', 'province', 'club']);

        $season = (string) Carbon::now()->year;
        $disciplines = $scope->for($user, $season);
        $dual = $scope->isDual($disciplines);

        if (! $dual) {
            $this->nextFilter = 'all';
            $this->resultsFilter = 'all';
        }

        $nextFilter = $this->normalizeFilter($this->nextFilter);
        $resultsFilter = $this->normalizeFilter($this->resultsFilter);

        // Hero discipline: use the user's choice if valid, otherwise the first
        // discipline they're active in. For single-discipline shooters this
        // collapses to just that discipline.
        $heroDisciplines = array_values(array_filter($disciplines, fn ($d) => in_array($d, ['PRS', 'PR22'], true)));
        $heroDiscipline = in_array($this->heroDiscipline, $heroDisciplines, true)
            ? $this->heroDiscipline
            : ($heroDisciplines[0] ?? 'PRS');

        $membership = $user->membership;
        $rifles = RifleConfiguration::forUser($user->id)
            ->active()
            ->with(['make', 'model', 'calibre'])
            ->orderMainsFirst()
            ->get();

        $heroes = $hero->build($user, $heroDisciplines, $season);
        $kpiData = $kpis->build($user, $heroDisciplines, $season);
        $dopeData = $dope->build($user, $rifles, $season);
        $stripData = $membershipStrip->build($user);

        return view('livewire.dashboard.shooter-dashboard', [
            'user' => $user,
            'season' => $season,
            'membership' => $membership,
            'disciplines' => $disciplines,
            'heroDisciplines' => $heroDisciplines,
            'heroDiscipline' => $heroDiscipline,
            'heroes' => $heroes,
            'kpis' => $kpiData,
            'dual' => $dual,
            'scopeNote' => $scope->scopeNote($disciplines),
            'nextMatches' => $nextMatches->build($user, $disciplines, $nextFilter, $season),
            'attention' => $attention->build($user, $disciplines, $season),
            'seasonCards' => $seasonCards->build($user, $disciplines, $season),
            'recentResults' => $this->recentResults($user, $disciplines, $resultsFilter, $season),
            'rifles' => $rifles->groupBy(fn (RifleConfiguration $r) => $r->primary_series ?: 'other'),
            'allRifles' => $rifles,
            'rifleDope' => $dopeData,
            'strip' => $stripData,
            'nextFilter' => $nextFilter,
            'resultsFilter' => $resultsFilter,
        ]);
    }

    /**
     * @param  list<string>  $disciplines
     * @return list<array<string, mixed>>
     */
    private function recentResults(User $user, array $disciplines, string $filter, string $season): array
    {
        $series = $filter === 'all'
            ? $disciplines
            : array_values(array_intersect($disciplines, [$filter]));

        if ($series === []) {
            return [];
        }

        return Score::query()
            ->with(['match.province'])
            ->where('user_id', $user->id)
            ->whereHas('match', function ($q) use ($series, $season) {
                $q->whereIn('series', $series)
                    ->where(function ($inner) use ($season) {
                        $inner->where('season', $season)->orWhereYear('match_date', $season);
                    });
            })
            ->orderByDesc(
                \App\Models\MatchEvent::select('match_date')
                    ->whereColumn('matches.id', 'scores.match_id')
                    ->limit(1)
            )
            ->limit(12)
            ->get()
            ->map(function (Score $score) {
                $match = $score->match;
                $place = $score->displayRank();
                $pct = $score->normalized_score !== null
                    ? round((float) $score->normalized_score, 1)
                    : null;
                $impacts = $score->raw_score !== null ? (int) round((float) $score->raw_score) : null;

                return [
                    'date' => $match?->match_date?->format('d M') ?? '—',
                    'date_full' => $match?->match_date?->format('d M Y') ?? '—',
                    'series' => $match?->series,
                    'name' => $match?->name ?? '—',
                    'href' => $match ? route('events.show', $match) : null,
                    'level' => match ($match?->series_level) {
                        'national' => 'National',
                        'provincial' => 'Provincial',
                        'final' => 'Final',
                        default => '',
                    },
                    'province' => $match?->province?->abbreviation ?? '—',
                    'place' => $place,
                    'match_pct' => $pct,
                    'impacts' => $impacts,
                ];
            })
            ->all();
    }

    private function normalizeFilter(string $filter): string
    {
        $filter = strtoupper($filter);

        return in_array($filter, ['ALL', 'PRS', 'PR22'], true)
            ? ($filter === 'ALL' ? 'all' : $filter)
            : 'all';
    }
}
