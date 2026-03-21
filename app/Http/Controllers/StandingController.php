<?php

namespace App\Http\Controllers;

use App\Models\Category;
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
        $categoryId = $request->filled('category_id') ? (int) $request->input('category_id') : null;

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

        $divisions = Division::active()->ordered()->forDiscipline($series)->get();
        $categories = Category::active()->ordered()->get();

        $base = Standing::with(['user', 'province', 'division', 'category'])
            ->where('season', $season)
            ->where('series', $series)
            ->orderBy('rank');

        if ($divisionId) {
            $base->where('division_id', $divisionId)->whereNull('category_id');
        } elseif ($categoryId) {
            $base->whereNull('division_id')->where('category_id', $categoryId);
        } else {
            $base->whereNull('division_id')->whereNull('category_id');
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
            'categoryId' => $categoryId,
            'divisions' => $divisions,
            'categories' => $categories,
            'provinces' => $provinces,
            'standings' => $standings,
        ]);
    }

    public function show(string $series, string $season): View
    {
        $standings = Standing::query()
            ->with(['user', 'province'])
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
        $categoryId = $request->filled('category_id') ? (int) $request->input('category_id') : null;
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
        $divisions = Division::active()->ordered()->forDiscipline($series)->get();
        $categories = Category::active()->ordered()->get();

        $base = Standing::with(['user.province', 'province', 'division', 'category'])
            ->where('season', $season)
            ->where('series', $series)
            ->orderBy('rank');

        if ($divisionId) {
            $base->where('division_id', $divisionId)->whereNull('category_id');
        } elseif ($categoryId) {
            $base->whereNull('division_id')->where('category_id', $categoryId);
        } else {
            $base->whereNull('division_id')->whereNull('category_id');
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
            ->whereNull('division_id')->whereNull('category_id');
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
            'categoryId' => $categoryId,
            'divisions' => $divisions,
            'categories' => $categories,
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
        $user->load('province');

        $scores = Score::with(['match.province', 'division', 'categories'])
            ->where('user_id', $user->id)
            ->whereHas('match', fn ($q) => $q->where('season', $season)->where('status', 'completed'))
            ->where('status', 'valid')
            ->orderByDesc('normalized_score')
            ->get();

        $standingsSummary = [];
        foreach (['PRS', 'PR22'] as $series) {
            $overall = Standing::where('user_id', $user->id)
                ->where('season', $season)
                ->where('series', $series)
                ->whereNull('province_id')
                ->whereNull('division_id')
                ->whereNull('category_id')
                ->first();

            if ($overall) {
                $divisionStanding = Standing::where('user_id', $user->id)
                    ->where('season', $season)
                    ->where('series', $series)
                    ->whereNull('province_id')
                    ->whereNotNull('division_id')
                    ->with('division')
                    ->first();

                $categoryStandings = Standing::where('user_id', $user->id)
                    ->where('season', $season)
                    ->where('series', $series)
                    ->whereNull('province_id')
                    ->whereNotNull('category_id')
                    ->with('category')
                    ->get();

                $standingsSummary[] = [
                    'series' => $series,
                    'overall_rank' => $overall->rank,
                    'overall_points' => $overall->points,
                    'division_name' => $divisionStanding?->division?->name,
                    'division_rank' => $divisionStanding?->rank,
                    'division_points' => $divisionStanding?->points,
                    'categories' => $categoryStandings->map(fn ($s) => [
                        'name' => $s->category?->name,
                        'rank' => $s->rank,
                        'points' => $s->points,
                    ])->toArray(),
                ];
            }
        }

        $rule = \App\Models\QualificationRule::where('season', $season)->first();
        $bestOf = $rule?->best_of_count;

        $matchesShot = $scores->count();
        $bestNormalized = $scores->max('normalized_score');

        return view('standings.shooter', [
            'shooter' => $user,
            'season' => $season,
            'scores' => $scores,
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
        $categoryId = $request->filled('category_id') ? (int) $request->input('category_id') : null;

        $query = Standing::query()
            ->with(['user:id,name,province_id', 'user.province:id,abbreviation', 'province:id,name', 'division:id,name,code', 'category:id,name,code'])
            ->when($request->filled('series'), fn ($q) => $q->where('series', $request->input('series')))
            ->when($request->filled('season'), fn ($q) => $q->where('season', $request->input('season')))
            ->when($request->filled('province_id'), fn ($q) => $q->where('province_id', $request->input('province_id')))
            ->whereNull('province_id')
            ->orderBy('rank');

        if ($divisionId) {
            $query->where('division_id', $divisionId)->whereNull('category_id');
        } elseif ($categoryId) {
            $query->whereNull('division_id')->where('category_id', $categoryId);
        } else {
            $query->whereNull('division_id')->whereNull('category_id');
        }

        $query->limit($request->filled('limit') ? (int) $request->input('limit') : 100);

        return response()->json(['data' => $query->get()]);
    }
}
