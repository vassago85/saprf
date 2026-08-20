<?php

namespace App\Services;

use App\Models\NationalTeamAppearance;
use App\Models\QualificationRule;
use App\Models\RifleConfiguration;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Composes the payloads shown on the public shooter profile page — both the
 * per-season detail view (the traditional /standings/{season}/shooter/{user}
 * page) and the season-less career view (/shooters/{saprfNumber}).
 *
 * Extracted from StandingController::publicShooter so the canonical
 * /shooters/{saprfNumber} URL and the legacy standings URL always render
 * from a single source of truth. The per-match contribution merger helpers
 * (previously private on the controller) live here too because they only
 * matter for the shooter profile.
 */
class ShooterProfileService
{
    public function __construct(
        private readonly ShooterStandingsSummaryService $standingsSummary,
    ) {}

    /**
     * Season detail payload — everything the per-season shooter view
     * (currently `standings.shooter` / `shooters._season-detail`) needs
     * to render its per-series breakdown cards and per-match table.
     *
     * @return array<string, mixed>
     */
    public function season(User $user, string $season): array
    {
        $user->loadMissing('province', 'division');

        // Every match the shooter attended in the season, across BOTH series.
        // Include all publicly visible statuses (not just 'valid') so matches
        // shot as a non-member / lapsed / pending still appear — they just
        // won't have contributed to the season log.
        $scores = Score::with(['match.province', 'division'])
            ->where('user_id', $user->id)
            ->whereHas('match', fn ($q) => $q->where('season', $season)->where('status', 'completed'))
            ->whereIn('status', ScoreValidationService::VISIBLE_STATUSES)
            ->get()
            ->sortByDesc(fn ($s) => optional($s->match?->match_date)->timestamp ?? 0)
            ->values();

        $standingsSummary = $this->standingsSummary->build($user, $season)->all();

        $contributionByMatch = [];
        foreach ($standingsSummary as $entry) {
            if (! empty($entry['pool_breakdown'])) {
                $this->mergeNationalContributions($contributionByMatch, $entry['series'], $entry['pool_breakdown']);
            }
            if (! empty($entry['provincial_pool_breakdown'])) {
                $this->mergeProvincialContributions($contributionByMatch, $entry['provincial_pool_breakdown']);
            }
        }

        $rule = QualificationRule::where('season', $season)->first();
        $bestOf = $rule?->best_of_count;

        $scoresBySeries = $scores->groupBy(fn ($s) => $s->match?->series);
        $summaryBySeries = collect($standingsSummary)->keyBy('series');
        $seriesOrder = collect(['PRS', 'PR22'])
            ->filter(fn ($s) => $scoresBySeries->has($s) || $summaryBySeries->has($s))
            ->values();

        $matchesShot = $scores->count();
        $bestNormalized = $scores->max('normalized_score');

        $matchDates = $scores
            ->pluck('match.match_date', 'match_id')
            ->filter()
            ->all();

        $profileRifles = RifleConfiguration::forUser($user->id)
            ->visibleOnProfile()
            ->with(['make', 'model', 'calibre', 'opticMake', 'opticModel'])
            ->orderMainsFirst()
            ->get();

        return [
            'shooter' => $user,
            'season' => $season,
            'scores' => $scores,
            'scoresBySeries' => $scoresBySeries,
            'summaryBySeries' => $summaryBySeries,
            'seriesOrder' => $seriesOrder,
            'standingsSummary' => $standingsSummary,
            'contributionByMatch' => $contributionByMatch,
            'matchesShot' => $matchesShot,
            'bestNormalized' => $bestNormalized ? round((float) $bestNormalized, 2) : null,
            'bestOf' => $bestOf,
            'rule' => $rule,
            'matchDates' => $matchDates,
            'profileRifles' => $profileRifles,
        ];
    }

    /**
     * Career-level payload — Protea colours + national-team appearances,
     * all-time totals, seasons the shooter has scores in. Independent of
     * any single season.
     *
     * `proteaColoursAppearance` is the single flagged NationalTeamAppearance
     * (or null); `nationalTeamAppearances` is the full list including that
     * one, ordered newest year first, for the "SA National Team Appearances"
     * table on the public profile.
     *
     * @return array{
     *     proteaColoursAppearance: ?NationalTeamAppearance,
     *     nationalTeamAppearances: Collection<int, NationalTeamAppearance>,
     *     careerStats: array{
     *         matches_attended:int,
     *         best_percent:?float,
     *         national_podiums:int,
     *         provincial_podiums:int,
     *         wins:int,
     *         first_match_date:?\Illuminate\Support\Carbon,
     *         latest_match_date:?\Illuminate\Support\Carbon,
     *         seasons_active:int
     *     },
     *     availableSeasons: Collection<int, string>,
     * }
     */
    public function career(User $user): array
    {
        $appearances = $user->nationalTeamAppearances()
            ->with(['division', 'selectionCycle'])
            ->orderByDesc('year')
            ->orderByDesc('appeared_at')
            ->get();

        // firstWhere returns null if the shooter has never been awarded
        // colours — the invariant of at-most-one awarded_colours=true
        // makes this a safe scalar lookup.
        $coloursAppearance = $appearances->firstWhere('awarded_colours', true);

        // One aggregate pass over the user's visible score history — cheaper
        // than doing four separate counts, and the row count stays small
        // enough (a shooter has at most a few hundred lifetime scores) that
        // pulling them into memory is fine.
        $scoreRows = Score::query()
            ->select(['id', 'match_id', 'status', 'overall_rank', 'normalized_score'])
            ->where('user_id', $user->id)
            ->whereIn('status', ScoreValidationService::VISIBLE_STATUSES)
            ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->with(['match:id,season,match_date,series_level'])
            ->get();

        $matchesAttended = $scoreRows->pluck('match_id')->unique()->count();
        $bestPercent = $scoreRows->max('normalized_score');
        $wins = $scoreRows->where('overall_rank', 1)->count();
        $nationalPodiums = $scoreRows
            ->filter(fn (Score $s) => in_array($s->match?->series_level, ['national', 'final'], true))
            ->where('overall_rank', '<=', 3)
            ->whereNotNull('overall_rank')
            ->count();
        $provincialPodiums = $scoreRows
            ->filter(fn (Score $s) => $s->match?->series_level === 'provincial')
            ->where('overall_rank', '<=', 3)
            ->whereNotNull('overall_rank')
            ->count();

        $matchDates = $scoreRows
            ->pluck('match.match_date')
            ->filter()
            ->sort()
            ->values();

        $availableSeasons = $scoreRows
            ->pluck('match.season')
            ->filter()
            ->map(fn ($s) => (string) $s)
            ->unique()
            ->sortDesc()
            ->values();

        return [
            'proteaColoursAppearance' => $coloursAppearance,
            'nationalTeamAppearances' => $appearances,
            'careerStats' => [
                'matches_attended' => $matchesAttended,
                'best_percent' => $bestPercent !== null ? round((float) $bestPercent, 2) : null,
                'national_podiums' => $nationalPodiums,
                'provincial_podiums' => $provincialPodiums,
                'wins' => $wins,
                'first_match_date' => $matchDates->first(),
                'latest_match_date' => $matchDates->last(),
                'seasons_active' => $availableSeasons->count(),
            ],
            'availableSeasons' => $availableSeasons,
        ];
    }

    /**
     * Merge national-standing contributions for a series into the shared
     * per-match map. Handles the PRS annual-log shape (regular[] + champs),
     * the PR22 weighted-pools shape (provincial/national/champs each with
     * matches[]), and the plain best-of-N shape (matches[] at the top).
     *
     * @param  array<int,array{series:string,counted_national:bool,national_pts:float,counted_provincial:bool,provincial_pts:float}>  $map
     */
    private function mergeNationalContributions(array &$map, string $series, ?array $breakdown): void
    {
        if (! is_array($breakdown) || empty($breakdown)) {
            return;
        }

        $mode = $breakdown['mode'] ?? null;

        if ($mode === 'annual_log') {
            foreach ($breakdown['regular'] ?? [] as $row) {
                $matchId = $row['match_id'] ?? null;
                if ($matchId === null) {
                    continue;
                }
                $this->applyNationalContribution($map, (int) $matchId, $series, true, (float) ($row['pct'] ?? 0));
            }
            if (! empty($breakdown['champs']) && isset($breakdown['champs']['match_id'])) {
                $this->applyNationalContribution(
                    $map,
                    (int) $breakdown['champs']['match_id'],
                    $series,
                    true,
                    (float) ($breakdown['champs']['pct'] ?? 0),
                );
            }

            return;
        }

        if ($mode === 'best_of_n') {
            foreach ($breakdown['matches'] ?? [] as $row) {
                $matchId = $row['match_id'] ?? null;
                if ($matchId === null) {
                    continue;
                }
                $counted = (bool) ($row['counted'] ?? false);
                $contribution = (float) ($row['contribution'] ?? 0);
                $this->applyNationalContribution($map, (int) $matchId, $series, $counted, $contribution);
            }

            return;
        }

        foreach (['provincial', 'national', 'champs'] as $poolKey) {
            $matches = $breakdown[$poolKey]['matches'] ?? [];
            foreach ($matches as $row) {
                $matchId = $row['match_id'] ?? null;
                if ($matchId === null) {
                    continue;
                }
                $counted = (bool) ($row['counted'] ?? false);
                $contribution = (float) ($row['contribution'] ?? 0);
                $this->applyNationalContribution($map, (int) $matchId, $series, $counted, $contribution);
            }
        }
    }

    /**
     * @param  array<int,array{series:string,counted_national:bool,national_pts:float,counted_provincial:bool,provincial_pts:float}>  $map
     */
    private function mergeProvincialContributions(array &$map, ?array $breakdown): void
    {
        if (! is_array($breakdown) || empty($breakdown['matches'])) {
            return;
        }

        foreach ($breakdown['matches'] as $row) {
            $matchId = $row['match_id'] ?? null;
            if ($matchId === null) {
                continue;
            }
            $counted = (bool) ($row['counted'] ?? false);
            $contribution = (float) ($row['contribution'] ?? 0);

            $entry = $map[(int) $matchId] ?? [
                'series' => 'PR22',
                'counted_national' => false,
                'national_pts' => 0.0,
                'counted_provincial' => false,
                'provincial_pts' => 0.0,
            ];

            $entry['series'] = 'PR22';
            $entry['counted_provincial'] = $entry['counted_provincial'] || $counted;
            $entry['provincial_pts'] += $counted ? $contribution : 0.0;

            $map[(int) $matchId] = $entry;
        }
    }

    private function applyNationalContribution(
        array &$map,
        int $matchId,
        string $series,
        bool $counted,
        float $contribution,
    ): void {
        $entry = $map[$matchId] ?? [
            'series' => $series,
            'counted_national' => false,
            'national_pts' => 0.0,
            'counted_provincial' => false,
            'provincial_pts' => 0.0,
        ];

        $entry['series'] = $series;
        $entry['counted_national'] = $entry['counted_national'] || $counted;
        $entry['national_pts'] += $counted ? $contribution : 0.0;

        $map[$matchId] = $entry;
    }
}
