<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\NationalTeamAppearance;
use App\Models\Province;
use App\Models\Standing;
use App\Models\User;
use App\Services\RankStandings;
use App\Services\SeasonStandingsBoard;
use App\Services\ShooterProfileService;
use App\Services\ShooterStandingsSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            // Deterministic order inside ties — see Volt mount() for the
            // "no more per-visit shuffle" rationale.
            null,
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

    /**
     * Discipline-specific landing page — /standings/prs renders PRS,
     * /standings/pr22 renders PR22. The series comes from the route
     * defaults (not a query param), so bookmarks are canonical and the
     * discipline switch is a real link to the other URL.
     */
    public function publicDisciplineIndex(Request $request, string $series): View
    {
        $series = in_array($series, ['PRS', 'PR22'], true) ? $series : 'PRS';

        return view('standings.discipline', ['series' => $series]);
    }

    /**
     * Legacy /standings 301 → /standings/prs (or /standings/pr22 when
     * ?series=PR22). Every other filter query param is forwarded so a
     * saved bookmark keeps its filtered view. The series param is
     * stripped from the forwarded query because the destination URL
     * carries it.
     */
    public function publicRedirect(Request $request): RedirectResponse
    {
        $requested = $request->query('series');
        $target = $requested === 'PR22' ? route('standings.pr22') : route('standings.prs');

        $forward = $request->query();
        unset($forward['series']);

        return redirect($target.($forward === [] ? '' : '?'.http_build_query($forward)), 301);
    }

    /**
     * Deprecated single-page standings view. Kept as a thin wrapper so any
     * cached routes / tests that still reference 'publicIndex' resolve;
     * the real route sends traffic through publicRedirect() above.
     */
    public function publicIndex(): View
    {
        return view('standings.discipline', ['series' => 'PRS']);
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
            ->whereNull('province_id');

        if ($divisionId) {
            $query->where('division_id', $divisionId);
        } else {
            $query->whereNull('division_id');
        }

        $limit = $request->filled('limit') ? (int) $request->input('limit') : 100;
        $limit = max(1, min($limit, 500));

        // The homepage asks for five rows but ranking ties needs the whole
        // filtered set. Cache the ranked slice, and include the row count
        // plus latest update so a recalculation is visible on the next hit.
        $stamp = (clone $query)
            ->setEagerLoads([])
            ->reorder()
            ->selectRaw('COUNT(*) as row_count, MAX(standings.updated_at) as row_updated')
            ->first();

        $cacheKey = 'standings.api.'.sha1(json_encode([
            'series' => $request->input('series'),
            'season' => $request->input('season'),
            'province_id' => $request->input('province_id'),
            'division_id' => $divisionId,
            'limit' => $limit,
            'rows' => (int) ($stamp->row_count ?? 0),
            'updated' => (string) ($stamp->row_updated ?? ''),
        ]));

        $ordered = Cache::remember($cacheKey, 60, function () use ($query, $limit) {
            // Deliberately NOT ordering by the stored rank — RankStandings
            // produces the canonical display order (rank asc, then surname /
            // first name / id), matching the standings page inside a tie.
            $models = $query->get();
            $ranker = app(RankStandings::class);

            $payload = $models->map(fn (Standing $s) => [
                'model' => $s,
                'points' => (float) $s->points,
                'name' => (string) ($s->user?->name ?? ''),
                'id' => (int) ($s->user_id ?? $s->id),
            ])->values();

            return $ranker->rank($payload, null)
                ->take($limit)
                ->map(function (array $row): Standing {
                    /** @var Standing $model */
                    $model = $row['model'];
                    $model->setAttribute('rank', $row['rank']);
                    $model->setAttribute('rank_label', $row['rank_label']);
                    $model->setAttribute('is_tied', $row['is_tied']);
                    $model->setAttribute('points_display', points((float) $model->points));

                    return $model;
                })
                ->values();
        });

        return response()->json(['data' => $ordered]);
    }
}
