<?php

namespace App\Livewire\Dashboard;

use App\Models\RifleConfiguration;
use App\Models\Score;
use App\Models\User;
use App\Support\Dashboard\Shooter\AttentionBuilder;
use App\Support\Dashboard\Shooter\DisciplineScope;
use App\Support\Dashboard\Shooter\NextMatchesBuilder;
use App\Support\Dashboard\Shooter\SeasonCardBuilder;
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

    public function setNextFilter(string $filter): void
    {
        $this->nextFilter = $this->normalizeFilter($filter);
    }

    public function setResultsFilter(string $filter): void
    {
        $this->resultsFilter = $this->normalizeFilter($filter);
    }

    public function render(
        DisciplineScope $scope,
        NextMatchesBuilder $nextMatches,
        AttentionBuilder $attention,
        SeasonCardBuilder $seasonCards,
    ) {
        /** @var User $user */
        $user = Auth::user();
        $user->loadMissing(['membership', 'province']);

        $season = (string) Carbon::now()->year;
        $disciplines = $scope->for($user, $season);
        $dual = $scope->isDual($disciplines);

        if (! $dual) {
            $this->nextFilter = 'all';
            $this->resultsFilter = 'all';
        }

        $nextFilter = $this->normalizeFilter($this->nextFilter);
        $resultsFilter = $this->normalizeFilter($this->resultsFilter);

        $membership = $user->membership;
        $rifles = RifleConfiguration::forUser($user->id)
            ->active()
            ->with(['make', 'model', 'calibre'])
            ->orderMainsFirst()
            ->get()
            ->groupBy(fn (RifleConfiguration $r) => $r->primary_series ?: 'other');

        return view('livewire.dashboard.shooter-dashboard', [
            'user' => $user,
            'season' => $season,
            'membership' => $membership,
            'disciplines' => $disciplines,
            'dual' => $dual,
            'scopeNote' => $scope->scopeNote($disciplines),
            'nextMatches' => $nextMatches->build($user, $disciplines, $nextFilter, $season),
            'attention' => $attention->build($user, $disciplines, $season),
            'seasonCards' => $seasonCards->build($user, $disciplines, $season),
            'recentResults' => $this->recentResults($user, $disciplines, $resultsFilter, $season),
            'rifles' => $rifles,
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
                $impacts = $score->raw_score !== null ? (int) round((float) $score->raw_score) : null;

                return [
                    'date' => $match?->match_date?->format('d M Y') ?? '—',
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
