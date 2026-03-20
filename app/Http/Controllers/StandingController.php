<?php

namespace App\Http\Controllers;

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
        $seasons = Standing::distinct()->pluck('season')->sort()->reverse()->values();
        $provinces = Province::orderBy('name')->get();

        if ($seasons->isEmpty()) {
            $seasons = collect([(string) now()->year]);
        }

        $base = Standing::with(['user', 'province'])->where('season', $season)->orderBy('rank');

        return view('standings.index', [
            'season' => $season,
            'seasons' => $seasons,
            'provinces' => $provinces,
            'prsNational' => (clone $base)->where('series', 'PRS')->whereNull('province_id')->get(),
            'pr22National' => (clone $base)->where('series', 'PR22')->whereNull('province_id')->get(),
            'prsProvincial' => (clone $base)->where('series', 'PRS')->whereNotNull('province_id')->get(),
            'pr22Provincial' => (clone $base)->where('series', 'PR22')->whereNotNull('province_id')->get(),
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
        $seasons = Standing::distinct()->pluck('season')->sort()->reverse()->values();

        if ($seasons->isEmpty()) {
            $seasons = collect([(string) now()->year]);
        }

        $base = Standing::with(['user.province', 'province'])->where('season', $season)->orderBy('rank');

        $prsNational = (clone $base)->where('series', 'PRS')->whereNull('province_id')->get();
        $pr22National = (clone $base)->where('series', 'PR22')->whereNull('province_id')->get();
        $prsProvincial = (clone $base)->where('series', 'PRS')->whereNotNull('province_id')->get();
        $pr22Provincial = (clone $base)->where('series', 'PR22')->whereNotNull('province_id')->get();

        $totalRanked = Standing::where('season', $season)->distinct('user_id')->count('user_id');
        $totalMatches = MatchEvent::where('season', $season)->published()->count();
        $completedMatches = MatchEvent::where('season', $season)->where('status', 'completed')->count();
        $remainingMatches = MatchEvent::where('season', $season)->where('match_date', '>=', now()->startOfDay())->whereIn('status', ['open', 'closed', 'draft'])->count();

        return view('standings.public', [
            'season' => $season,
            'seasons' => $seasons,
            'activeSeries' => 'PRS',
            'activeLevel' => 'national',
            'prsNational' => $prsNational,
            'pr22National' => $pr22National,
            'prsProvincial' => $prsProvincial,
            'pr22Provincial' => $pr22Provincial,
            'totalRanked' => $totalRanked,
            'totalMatches' => $totalMatches,
            'completedMatches' => $completedMatches,
            'remainingMatches' => $remainingMatches,
        ]);
    }

    public function publicShooter(string $season, User $user): View
    {
        $user->load('province');

        $scores = Score::with(['match.province'])
            ->where('user_id', $user->id)
            ->whereHas('match', fn ($q) => $q->where('season', $season)->where('status', 'completed'))
            ->where('status', 'valid')
            ->orderBy('created_at')
            ->get();

        $standingsSummary = [];
        foreach (['PRS', 'PR22'] as $series) {
            $standing = Standing::where('user_id', $user->id)
                ->where('season', $season)
                ->where('series', $series)
                ->whereNull('province_id')
                ->first();

            if ($standing) {
                $standingsSummary[] = [
                    'series' => $series,
                    'rank' => $standing->rank,
                    'points' => $standing->points,
                ];
            }
        }

        $matchesShot = $scores->count();
        $bestPlacement = $scores->whereNotNull('placement')->min('placement');
        $avgPlacement = $scores->whereNotNull('placement')->avg('placement');
        $totalPoints = collect($standingsSummary)->sum('points');

        return view('standings.shooter', [
            'shooter' => $user,
            'season' => $season,
            'scores' => $scores,
            'standingsSummary' => $standingsSummary,
            'matchesShot' => $matchesShot,
            'bestPlacement' => $bestPlacement,
            'avgPlacement' => $avgPlacement ? round($avgPlacement, 1) : null,
            'totalPoints' => $totalPoints,
        ]);
    }

    // ── API ──

    public function apiIndex(Request $request): JsonResponse
    {
        $query = Standing::query()
            ->with(['user:id,name,province_id', 'user.province:id,abbreviation', 'province:id,name'])
            ->when($request->filled('series'), fn ($q) => $q->where('series', $request->input('series')))
            ->when($request->filled('season'), fn ($q) => $q->where('season', $request->input('season')))
            ->when($request->filled('province_id'), fn ($q) => $q->where('province_id', $request->input('province_id')))
            ->whereNull('province_id')
            ->orderBy('rank');

        if ($request->filled('limit')) {
            $query->limit((int) $request->input('limit'));
        } else {
            $query->limit(100);
        }

        return response()->json(['data' => $query->get()]);
    }
}
