<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Standing;
use App\Models\User;
use App\Services\QualificationService;
use App\Services\ShooterProfileService;
use App\Services\ShooterStandingsSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StandingController extends Controller
{
    public function __construct(
        private readonly ShooterStandingsSummaryService $standingsSummary,
        private readonly QualificationService $qualification,
        private readonly ShooterProfileService $shooterProfile,
    ) {}

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

        // Sort resolution. Sort key is whitelisted; anything unknown is
        // ignored so we always fall through to the default below.
        $sortableColumns = ['points', 'rank', 'shooter', 'division', 'province'];
        $requestedSort = $request->input('sort');
        $sort = in_array($requestedSort, $sortableColumns, true) ? $requestedSort : null;

        $requestedDirection = strtolower((string) $request->input('direction', ''));
        $direction = in_array($requestedDirection, ['asc', 'desc'], true) ? $requestedDirection : null;

        // Default sort: rank asc only when the visible slice is a *single
        // ranking scope* (both division AND province filters applied on the
        // provincial view, or division filter on national), because rank is
        // computed within (division, province). Otherwise sort by points
        // desc — the row-level "rank 1" repeats once per scope and looks
        // like a bug in the multi-scope views (see screenshot in issue).
        if ($sort === null) {
            $isSingleScope = $level === 'provincial'
                ? ($divisionId !== null && $provinceFilter !== null)
                : ($divisionId !== null);
            $sort = $isSingleScope ? 'rank' : 'points';
        }
        if ($direction === null) {
            $direction = $sort === 'rank' ? 'asc' : 'desc';
        }

        $seasons = Standing::distinct()->pluck('season')->sort()->reverse()->values();
        if ($seasons->isEmpty()) {
            $seasons = collect([(string) now()->year]);
        }

        $provinces = Province::orderBy('name')->get();
        $divisions = Division::active()->ordered()->get();

        // Qualify all filter columns with the `standings.` prefix — some sort
        // options join `users` (which also has `province_id` / `division_id`),
        // so bare column names become ambiguous under SQLite/MySQL.
        $base = Standing::with(['user.province', 'user.division', 'province', 'division'])
            ->where('standings.season', $season)
            ->where('standings.series', $series);

        if ($divisionId) {
            $base->where('standings.division_id', $divisionId);
        } else {
            $base->whereNull('standings.division_id');
        }

        if ($level === 'provincial') {
            $standings = (clone $base)->whereNotNull('standings.province_id');
        } else {
            $standings = (clone $base)->whereNull('standings.province_id');
        }

        if ($provinceFilter) {
            if ($level === 'provincial') {
                $standings->where('standings.province_id', $provinceFilter);
            } else {
                $standings->whereHas('user', fn ($q) => $q->where('users.province_id', $provinceFilter));
            }
        }

        // Ranked-shooter count must match the filters currently shown in the
        // table (level, division, province) — otherwise the header can read
        // "81 Ranked Shooters" while the table lists 64, which the user
        // (rightly) reads as a bug. Snapshot the count BEFORE adding any
        // sort-related joins so we don't accidentally multiply rows.
        $totalRanked = (clone $standings)->distinct('user_id')->count('user_id');

        // Apply sort. Joins are scoped to the display query only; the count
        // above already ran on the pristine query. `select('standings.*')`
        // keeps Eloquent hydration on the right table when we join others.
        switch ($sort) {
            case 'shooter':
                $standings
                    ->leftJoin('users', 'standings.user_id', '=', 'users.id')
                    ->select('standings.*')
                    ->orderBy('users.name', $direction);
                break;
            case 'division':
                $standings
                    ->leftJoin('divisions', 'standings.division_id', '=', 'divisions.id')
                    ->select('standings.*')
                    ->orderBy('divisions.name', $direction);
                break;
            case 'province':
                if ($level === 'provincial') {
                    $standings
                        ->leftJoin('provinces', 'standings.province_id', '=', 'provinces.id')
                        ->select('standings.*')
                        ->orderBy('provinces.name', $direction);
                } else {
                    $standings
                        ->leftJoin('users', 'standings.user_id', '=', 'users.id')
                        ->leftJoin('provinces', 'users.province_id', '=', 'provinces.id')
                        ->select('standings.*')
                        ->orderBy('provinces.name', $direction);
                }
                break;
            case 'rank':
                $standings->orderBy('standings.rank', $direction);
                break;
            case 'points':
            default:
                $standings->orderBy('standings.points', $direction);
                break;
        }

        // Stable tie-breaker so equal-value rows have a deterministic order.
        $standings->orderBy('standings.id');

        $totalMatches = MatchEvent::where('season', $season)->where('match_type', $series)->published()->count();
        $completedMatches = MatchEvent::where('season', $season)->where('match_type', $series)->where('status', 'completed')->count();
        $remainingMatches = MatchEvent::where('season', $season)->where('match_type', $series)
            ->where('match_date', '>=', now()->startOfDay())
            ->whereIn('status', ['open', 'closed', 'draft'])->count();

        $standingsResult = $standings->get();

        // Finale-eligibility ✓ next to each name (PRS-style). One bulk query
        // for the whole page — QualificationService::bulkFinalsQualification()
        // joins scores × match_events × users and returns a per-user map.
        // Empty map when there's no rule or nobody's on the page.
        $qualificationByUser = $this->qualification->bulkFinalsQualification(
            $standingsResult->pluck('user_id')->filter()->unique()->values()->all(),
            $series,
            $season,
        );

        return view('standings.public', [
            'season' => $season,
            'seasons' => $seasons,
            'series' => $series,
            'level' => $level,
            'divisionId' => $divisionId,
            'divisions' => $divisions,
            'provinceFilter' => $provinceFilter,
            'provinces' => $provinces,
            'standings' => $standingsResult,
            'qualificationByUser' => $qualificationByUser,
            'totalRanked' => $totalRanked,
            'totalMatches' => $totalMatches,
            'completedMatches' => $completedMatches,
            'remainingMatches' => $remainingMatches,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    /**
     * Legacy per-season shooter view. New links go to
     * /shooters/{saprfNumber}/{season} via ShooterProfileController, which
     * builds off the same ShooterProfileService::season() payload. This
     * method stays live so the many {{ url('/standings/'.$season.'/shooter/'
     * .$user->id) }} template links and existing bookmarks still resolve
     * (they 301 to the canonical URL when the user has a SAPRF number,
     * and fall through to this render otherwise — see routes/web.php).
     */
    public function publicShooter(string $season, User $user): View
    {
        return view('standings.shooter', $this->shooterProfile->season($user, $season));
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
