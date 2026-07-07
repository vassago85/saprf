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

        $rankedQuery = Standing::where('season', $season)->where('series', $series)
            ->whereNull('division_id');
        $totalRanked = $rankedQuery->distinct('user_id')->count('user_id');
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
        foreach (['PRS', 'PR22'] as $series) {
            $overall = Standing::where('user_id', $user->id)
                ->where('season', $season)
                ->where('series', $series)
                ->whereNull('province_id')
                ->whereNull('division_id')
                ->first();

            if ($overall) {
                $divisionStanding = Standing::where('user_id', $user->id)
                    ->where('season', $season)
                    ->where('series', $series)
                    ->whereNull('province_id')
                    ->whereNotNull('division_id')
                    ->with('division')
                    ->first();

                $seriesRule = \App\Models\QualificationRule::where('season', $season)
                    ->where('series', $series)
                    ->first();

                $standingsSummary[] = [
                    'series' => $series,
                    'overall_rank' => $overall->rank,
                    'overall_points' => $overall->points,
                    'pool_breakdown' => $overall->pool_breakdown,
                    'scoring_mode' => $seriesRule?->scoring_mode ?? 'best_of_n',
                    'division_name' => $divisionStanding?->division?->name,
                    'division_rank' => $divisionStanding?->rank,
                    'division_points' => $divisionStanding?->points,
                ];
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
            'matchesShot' => $matchesShot,
            'bestNormalized' => $bestNormalized ? round($bestNormalized, 2) : null,
            'bestOf' => $bestOf,
            'rule' => $rule,
        ]);
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
