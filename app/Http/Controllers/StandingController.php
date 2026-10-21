<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\NationalTeamAppearance;
use App\Models\Province;
use App\Models\Standing;
use App\Models\User;
use App\Services\SeasonStandingsBoard;
use App\Services\ShooterProfileService;
use App\Services\ShooterStandingsSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StandingController extends Controller
{
    public function __construct(
        private readonly ShooterStandingsSummaryService $standingsSummary,
        private readonly ShooterProfileService $shooterProfile,
        private readonly SeasonStandingsBoard $standingsBoard,
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

        $standings = $this->standingsBoard->load(
            $season,
            $series,
            $level,
            $divisionId,
            null,
            null,
            null,
            random_int(1, 2147483646),
        )->standings;

        $saBadgesByUser = NationalTeamAppearance::badgeDataForUsers(
            $standings->pluck('user_id')->filter()->unique()->values()->all(),
        );

        return view('standings.index', [
            'season' => $season,
            'seasons' => $seasons,
            'series' => $series,
            'level' => $level,
            'divisionId' => $divisionId,
            'divisions' => $divisions,
            'provinces' => $provinces,
            'standings' => $standings,
            'saBadgesByUser' => $saBadgesByUser,
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

    public function publicIndex(): View
    {
        return view('standings.public');
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
