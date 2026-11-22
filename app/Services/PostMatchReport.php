<?php

namespace App\Services;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\Standing;
use App\Services\ScoreValidationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Championship post-match report.
 *
 * For a `series_level = final` match, lists every division, the top 5
 * shooters in that division's year standing, and for each of them
 *   - the championship contribution added by today's match,
 *   - the nationals / international that actually count on their year
 *     (from the standings pool_breakdown),
 *   - the division year winner.
 *
 * The shooter list is deliberately narrowed to "people who have a
 * visible championship score row" — it is a match report, not the
 * public season log. A top-5 year shooter who skipped the champs is
 * left off so the report never tries to answer what their total
 * would have been if they shot.
 */
class PostMatchReport
{
    /**
     * @return array{
     *   match: MatchEvent,
     *   series: string,
     *   season: string,
     *   generated_at: Carbon,
     *   has_standings: bool,
     *   divisions: list<array{
     *     division: Division,
     *     winner: ?array<string, mixed>,
     *     top5: list<array<string, mixed>>,
     *     shooters_total: int,
     *     year_max: ?int,
     *   }>,
     * }
     */
    public function build(MatchEvent $match): array
    {
        if ($match->series_level !== 'final') {
            throw new InvalidArgumentException('The post-match report is only built for championship (final) matches.');
        }

        $series = (string) $match->series;
        $season = (string) ($match->season ?: $match->match_date?->year);

        $scores = Score::query()
            ->with(['user:id,name', 'division:id,name,display_order'])
            ->where('match_id', $match->id)
            ->whereIn('status', ScoreValidationService::VISIBLE_STATUSES)
            ->whereNotNull('user_id')
            ->whereNotNull('division_id')
            ->get();

        $userIds = $scores->pluck('user_id')->unique()->filter()->values();

        $standings = $userIds->isEmpty()
            ? collect()
            : Standing::query()
                ->with(['user:id,name', 'division:id,name,display_order'])
                ->where('series', $series)
                ->where('season', $season)
                ->whereNull('province_id')
                ->whereNotNull('division_id')
                ->whereIn('user_id', $userIds)
                ->get();

        $divisions = Division::query()
            ->whereIn('id', $scores->pluck('division_id')->unique()->filter())
            ->ordered()
            ->get();

        $divisionBlocks = $divisions->map(function (Division $division) use ($scores, $standings) {
            return $this->divisionBlock($division, $scores, $standings);
        })->values()->all();

        return [
            'match' => $match,
            'series' => $series,
            'season' => $season,
            'generated_at' => now(),
            'has_standings' => $standings->isNotEmpty(),
            'divisions' => $divisionBlocks,
        ];
    }

    /**
     * @param  Collection<int, Score>  $allScores
     * @param  Collection<int, Standing>  $allStandings
     * @return array<string, mixed>
     */
    private function divisionBlock(Division $division, Collection $allScores, Collection $allStandings): array
    {
        $divScores = $allScores->where('division_id', $division->id)->values();
        $userIdsInDiv = $divScores->pluck('user_id')->unique()->filter()->values();

        // Standings are per-shooter per-division. A shooter who shot Open
        // at the champs is read from the Open standings — exactly the
        // ranking they appear in on the public board.
        $divStandings = $allStandings
            ->where('division_id', $division->id)
            ->whereIn('user_id', $userIdsInDiv)
            ->sortBy([
                ['rank', 'asc'],
                ['user.name', 'asc'],
            ])
            ->values();

        $top5Standings = $divStandings->take(5)->values();

        $rows = $top5Standings->map(
            fn (Standing $standing) => $this->shooterRow($standing, $divScores)
        )->all();

        $yearMax = $top5Standings->first()?->pool_breakdown['max'] ?? null;

        return [
            'division' => $division,
            'winner' => $rows[0] ?? null,
            'top5' => $rows,
            'shooters_total' => $userIdsInDiv->count(),
            'year_max' => is_int($yearMax) ? $yearMax : (is_numeric($yearMax) ? (int) $yearMax : null),
        ];
    }

    /**
     * @param  Collection<int, Score>  $divScores
     * @return array<string, mixed>
     */
    private function shooterRow(Standing $standing, Collection $divScores): array
    {
        $score = $divScores->firstWhere('user_id', $standing->user_id);
        $pb = is_array($standing->pool_breakdown) ? $standing->pool_breakdown : [];

        $todayPoints = (float) ($pb['champs']['pct'] ?? $pb['champs_pct'] ?? 0);
        $todayMatchPct = isset($pb['champs']['match_pct'])
            ? (float) $pb['champs']['match_pct']
            : null;

        // The counted-regular list is the same `regular[]` the standings
        // page and the shooter profile render — it already dropped
        // whatever had to drop (home-province cap, best-of cut).
        $counted = collect($pb['regular'] ?? [])
            ->map(fn (array $row) => [
                'match_id' => $row['match_id'] ?? null,
                'match_name' => $row['match_name'] ?? 'Match #'.($row['match_id'] ?? '?'),
                'level' => $row['level'] ?? null,
                'pct' => (float) ($row['pct'] ?? 0),
                'home' => (bool) ($row['home'] ?? false),
            ])
            ->values()
            ->all();

        return [
            'rank' => (int) $standing->rank,
            'rank_label' => Standing::rankLabelFor($standing),
            'user' => $standing->user,
            'division_id' => $standing->division_id,
            'today_raw_score' => $score ? (float) $score->raw_score : null,
            'today_points' => $todayPoints,
            'today_match_pct' => $todayMatchPct,
            'year_points' => (float) $standing->points,
            'year_max' => $pb['max'] ?? null,
            'regular_total' => (float) ($pb['regular_total'] ?? 0),
            'counted_regulars' => $counted,
            'nationals_shot' => (int) ($pb['nationals_shot'] ?? count($counted)),
            'regular_best_of' => (int) ($pb['regular_best_of'] ?? count($counted)),
        ];
    }
}
