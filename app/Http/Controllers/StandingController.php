<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StandingController extends Controller
{
    // ── Authenticated Dashboard Standings ──

    public function index(Request $request): View
    {
        $season = $request->input('season', (string) now()->year);
        $series = $request->input('series', 'PRS');
        $level = $request->input('level', 'national');
        $divisionId = $request->filled('division_id') ? (int) $request->input('division_id') : null;

        if (! in_array($series, ['PRS', 'PR22'])) {
            $series = 'PRS';
        }
        if (! in_array($level, ['national', 'provincial'])) {
            $level = 'national';
        }

        $seasons = Standing::distinct()->pluck('season')->sort()->reverse()->values();
        $provinces = Province::orderBy('name')->get();

        if ($seasons->isEmpty()) {
            $seasons = collect([(string) now()->year]);
        }

        $divisions = Division::active()->ordered()->get();

        $base = Standing::with(['user.division', 'province', 'division'])
            ->where('season', $season)
            ->where('series', $series)
            ->orderBy('rank');

        if ($divisionId) {
            $base->where('division_id', $divisionId);
        } else {
            $base->whereNull('division_id');
        }

        if ($level === 'provincial') {
            $standings = (clone $base)->whereNotNull('province_id')->get();
        } else {
            $standings = (clone $base)->whereNull('province_id')->get();
        }

        return view('standings.index', [
            'season' => $season,
            'seasons' => $seasons,
            'series' => $series,
            'level' => $level,
            'divisionId' => $divisionId,
            'divisions' => $divisions,
            'provinces' => $provinces,
            'standings' => $standings,
        ]);
    }

    public function show(string $series, string $season): View
    {
        $standings = Standing::query()
            ->with(['user.division', 'province'])
            ->where('series', $series)
            ->where('season', $season)
            ->orderBy('rank')
            ->get();

        return view('standings.show', compact('standings', 'series', 'season'));
    }

    // ── Public Standings ──

    public function publicIndex(Request $request): View
    {
        $season = $request->input('season', (string) now()->year);
        $series = $request->input('series', 'PRS');
        $level = $request->input('level', 'national');
        $divisionId = $request->filled('division_id') ? (int) $request->input('division_id') : null;
        $provinceFilter = $request->filled('province_id') ? (int) $request->input('province_id') : null;

        if (! in_array($series, ['PRS', 'PR22'])) {
            $series = 'PRS';
        }
        if (! in_array($level, ['national', 'provincial'])) {
            $level = 'national';
        }

        $seasons = Standing::distinct()->pluck('season')->sort()->reverse()->values();
        if ($seasons->isEmpty()) {
            $seasons = collect([(string) now()->year]);
        }

        $provinces = Province::orderBy('name')->get();
        $divisions = Division::active()->ordered()->get();

        $base = Standing::with(['user.province', 'user.division', 'province', 'division'])
            ->where('season', $season)
            ->where('series', $series)
            ->orderBy('rank');

        if ($divisionId) {
            $base->where('division_id', $divisionId);
        } else {
            $base->whereNull('division_id');
        }

        if ($level === 'provincial') {
            $standings = (clone $base)->whereNotNull('province_id');
        } else {
            $standings = (clone $base)->whereNull('province_id');
        }

        if ($provinceFilter) {
            if ($level === 'provincial') {
                $standings->where('province_id', $provinceFilter);
            } else {
                $standings->whereHas('user', fn ($q) => $q->where('province_id', $provinceFilter));
            }
        }

        // Ranked-shooter count must match the filters currently shown in the
        // table (level, division, province) — otherwise the header can read
        // "81 Ranked Shooters" while the table lists 64, which the user
        // (rightly) reads as a bug.
        $totalRanked = (clone $standings)->distinct('user_id')->count('user_id');
        $totalMatches = MatchEvent::where('season', $season)->where('match_type', $series)->published()->count();
        $completedMatches = MatchEvent::where('season', $season)->where('match_type', $series)->where('status', 'completed')->count();
        $remainingMatches = MatchEvent::where('season', $season)->where('match_type', $series)
            ->where('match_date', '>=', now()->startOfDay())
            ->whereIn('status', ['open', 'closed', 'draft'])->count();

        return view('standings.public', [
            'season' => $season,
            'seasons' => $seasons,
            'series' => $series,
            'level' => $level,
            'divisionId' => $divisionId,
            'divisions' => $divisions,
            'provinceFilter' => $provinceFilter,
            'provinces' => $provinces,
            'standings' => $standings->get(),
            'totalRanked' => $totalRanked,
            'totalMatches' => $totalMatches,
            'completedMatches' => $completedMatches,
            'remainingMatches' => $remainingMatches,
        ]);
    }

    public function publicShooter(string $season, User $user): View
    {
        $user->load('province', 'division');

        // Every match the shooter attended in the season, across BOTH series.
        // Include all publicly visible statuses (not just 'valid') so matches
        // shot as a non-member / lapsed / pending still appear here — they just
        // won't have contributed to the season log.
        $scores = Score::with(['match.province', 'division'])
            ->where('user_id', $user->id)
            ->whereHas('match', fn ($q) => $q->where('season', $season)->where('status', 'completed'))
            ->whereIn('status', \App\Services\ScoreValidationService::VISIBLE_STATUSES)
            ->get()
            ->sortByDesc(fn ($s) => optional($s->match?->match_date)->timestamp ?? 0)
            ->values();

        $standingsSummary = [];
        // Per-match contribution map, keyed by match_id. Each entry describes
        // whether that match counted toward the shooter's national and/or
        // provincial standing, and the points it contributed. Populated below
        // from the persisted pool_breakdown of each Standing row.
        $contributionByMatch = [];
        foreach (['PRS', 'PR22'] as $series) {
            $overall = Standing::where('user_id', $user->id)
                ->where('season', $season)
                ->where('series', $series)
                ->whereNull('province_id')
                ->whereNull('division_id')
                ->first();

            $seriesRule = \App\Models\QualificationRule::where('season', $season)
                ->where('series', $series)
                ->first();

            // Provincial standing (PR22 only — PRS has no provincial variant).
            // Loaded here regardless so the summary knows about it even if the
            // shooter has no national row yet, and so we can pull its
            // pool_breakdown for per-match provincial contributions.
            $provincial = null;
            $provincialDivisions = collect();
            if ($series === 'PR22' && $user->province_id) {
                $provincial = Standing::where('user_id', $user->id)
                    ->where('season', $season)
                    ->where('series', 'PR22')
                    ->where('province_id', $user->province_id)
                    ->whereNull('division_id')
                    ->first();

                // ALL provincial division standings — a shooter may compete
                // in multiple divisions across the season (e.g. Open in one
                // match, Factory in another) and gets a separate ranking in
                // each. `->first()` silently hid every division past the
                // first. Sort by the division's display_order so the UI
                // presentation is stable and matches the standings tables.
                $provincialDivisions = Standing::where('user_id', $user->id)
                    ->where('season', $season)
                    ->where('series', 'PR22')
                    ->where('province_id', $user->province_id)
                    ->whereNotNull('division_id')
                    ->with('division')
                    ->get()
                    ->sortBy(fn (Standing $s) => $s->division?->display_order ?? 999)
                    ->values();

                if ($provincial) {
                    $this->mergeProvincialContributions($contributionByMatch, $provincial->pool_breakdown);
                }
            }

            if ($overall || $provincial) {
                // Same treatment for national division standings — load all
                // divisions the shooter placed in, not just the first row.
                $divisionStandings = $overall
                    ? Standing::where('user_id', $user->id)
                        ->where('season', $season)
                        ->where('series', $series)
                        ->whereNull('province_id')
                        ->whereNotNull('division_id')
                        ->with('division')
                        ->get()
                        ->sortBy(fn (Standing $s) => $s->division?->display_order ?? 999)
                        ->values()
                    : collect();

                $standingsSummary[] = [
                    'series' => $series,
                    // National standing
                    'overall_rank' => $overall?->rank,
                    'overall_points' => $overall?->points,
                    'pool_breakdown' => $overall?->pool_breakdown,
                    'scoring_mode' => $seriesRule?->scoring_mode ?? 'best_of_n',
                    'divisions' => $divisionStandings->map(fn (Standing $s) => [
                        'name' => $s->division?->name,
                        'rank' => $s->rank,
                        'points' => $s->points,
                    ])->all(),
                    // Provincial standing (PR22 only)
                    'has_provincial' => (bool) $provincial,
                    'province_name' => $user->province?->name,
                    'provincial_rank' => $provincial?->rank,
                    'provincial_points' => $provincial?->points,
                    'provincial_pool_breakdown' => $provincial?->pool_breakdown,
                    'provincial_divisions' => $provincialDivisions->map(fn (Standing $s) => [
                        'name' => $s->division?->name,
                        'rank' => $s->rank,
                        'points' => $s->points,
                    ])->all(),
                ];

                if ($overall) {
                    $this->mergeNationalContributions($contributionByMatch, $series, $overall->pool_breakdown);
                }
            }
        }

        $rule = \App\Models\QualificationRule::where('season', $season)->first();
        $bestOf = $rule?->best_of_count;

        // Group attended matches by series and key the ranking summary by series
        // so the view can render a card per series the shooter took part in —
        // even one where they have scores but no ranking (e.g. all non-member).
        $scoresBySeries = $scores->groupBy(fn ($s) => $s->match?->series);
        $summaryBySeries = collect($standingsSummary)->keyBy('series');
        $seriesOrder = collect(['PRS', 'PR22'])
            ->filter(fn ($s) => $scoresBySeries->has($s) || $summaryBySeries->has($s))
            ->values();

        $matchesShot = $scores->count();
        $bestNormalized = $scores->max('normalized_score');

        return view('standings.shooter', [
            'shooter' => $user,
            'season' => $season,
            'scores' => $scores,
            'scoresBySeries' => $scoresBySeries,
            'summaryBySeries' => $summaryBySeries,
            'seriesOrder' => $seriesOrder,
            'standingsSummary' => $standingsSummary,
            'contributionByMatch' => $contributionByMatch,
            'matchesShot' => $matchesShot,
            'bestNormalized' => $bestNormalized ? round($bestNormalized, 2) : null,
            'bestOf' => $bestOf,
            'rule' => $rule,
        ]);
    }

    /**
     * Merge national-standing contributions for a series into the shared
     * per-match map. Handles both the PRS annual-log shape (regular[] +
     * champs) and the PR22 weighted-pools shape (provincial/national/champs
     * with per-pool matches[]). A dual-count PR22 national appears in both
     * the national pool and the provincial pool of the national standing —
     * we sum both pool contributions into a single national-standing figure
     * for that match.
     *
     * @param  array<int,array{series:string,counted_national:bool,national_pts:float,counted_provincial:bool,provincial_pts:float}>  $map
     */
    private function mergeNationalContributions(array &$map, string $series, ?array $breakdown): void
    {
        if (! is_array($breakdown) || empty($breakdown)) {
            return;
        }

        $mode = $breakdown['mode'] ?? null;

        // PRS annual log: named regular matches + champs.
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

        // Best-of-N sum mode (aggregateSeasonTotals): matches[] at the top
        // level, each row already carrying `counted` + `contribution`. This is
        // the fallback path used when a series has no QualificationRule
        // configured (or one that's not weighted_pools / best_n_plus_champs) —
        // without this branch every match on the shooter page would render as
        // DROPPED even though the aggregate total counts them correctly.
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

        // PR22 weighted pools: iterate every pool's matches[] and sum the
        // per-match contribution into the national-standing figure.
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
     * Merge provincial-standing contributions (PR22 only) into the shared
     * per-match map. The provincial standing uses aggregateSeasonTotals()
     * (best-of-N sum), so each counted match's contribution is the
     * normalized value that went into the sum.
     *
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

            // Counted flag is sticky-true; contributions accumulate.
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

    // ── API ──

    public function apiIndex(Request $request): JsonResponse
    {
        $divisionId = $request->filled('division_id') ? (int) $request->input('division_id') : null;

        $query = Standing::query()
            ->with([
                'user:id,name,province_id,division_id',
                'user.province:id,abbreviation',
                'user.division:id,name,slug',
                'province:id,name',
                'division:id,name,slug',
            ])
            ->when($request->filled('series'), fn ($q) => $q->where('series', $request->input('series')))
            ->when($request->filled('season'), fn ($q) => $q->where('season', $request->input('season')))
            ->when($request->filled('province_id'), fn ($q) => $q->where('province_id', $request->input('province_id')))
            ->whereNull('province_id')
            ->orderBy('rank');

        if ($divisionId) {
            $query->where('division_id', $divisionId);
        } else {
            $query->whereNull('division_id');
        }

        $query->limit($request->filled('limit') ? (int) $request->input('limit') : 100);

        return response()->json(['data' => $query->get()]);
    }
}
