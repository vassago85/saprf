<?php

namespace App\Http\Controllers;

use App\Http\Controllers\ApprovalController;
use App\Models\AuditLog;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\ProvincialCommittee;
use App\Models\QualificationRule;
use App\Models\RifleConfiguration;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\QualificationService;
use App\Services\SettingsService;
use App\Services\ShooterStandingsSummaryService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    // Highest-priority role first. A user with multiple roles lands on the
    // dashboard for the first role they have in this list.
    private const ROLE_PRIORITY = [
        'developer',
        'exco',
        'owner',
        'admin',
        'match_director',
        'provincial_admin',
        'member',
    ];

    public function __construct(
        private QualificationService $qualificationService,
        private SettingsService $settingsService,
        private ShooterStandingsSummaryService $shooterStandingsSummary,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Dev impersonation: local env only. In production every user lands on
        // the dashboard for their actual role.
        $canImpersonate = app()->isLocal();
        $viewAs = $canImpersonate ? $request->input('view_as') : null;

        // Shooter/Admin toggle: staff users can flip to their own shooter view
        // via the sidebar switch. When in shooter mode, always render the
        // member dashboard regardless of their staff role.
        if (! $viewAs && $user->effectiveViewMode() === 'shooter') {
            return $this->memberDashboard($user)->with([
                'devSwitcherEnabled' => $canImpersonate,
                'currentViewAs' => 'member',
            ]);
        }

        $role = $viewAs && in_array($viewAs, self::ROLE_PRIORITY, true)
            ? $viewAs
            : $this->resolveRole($user);

        $dashboard = match ($role) {
            'developer' => $this->developerDashboard($user),
            'exco' => $this->ownerDashboard($user),
            'owner' => $this->ownerDashboard($user),
            'admin' => $this->adminDashboard($user),
            'match_director' => $this->matchDirectorDashboard($user),
            'provincial_admin' => $this->provincialAdminDashboard($user),
            default => $this->memberDashboard($user),
        };

        return $dashboard->with([
            'devSwitcherEnabled' => $canImpersonate,
            'currentViewAs' => $role,
        ]);
    }

    /**
     * Flip the session view-mode flag between `admin` and `shooter`. Only
     * users who hold a staff role are allowed to switch — everyone else is
     * pinned to the shooter experience.
     */
    public function switchViewMode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'in:admin,shooter'],
        ]);

        $user = $request->user();
        if (! $user->canSwitchViewMode()) {
            return redirect()->route('dashboard');
        }

        $request->session()->put('view_mode', $validated['mode']);

        return redirect()->route('dashboard');
    }

    private function resolveRole(User $user): string
    {
        foreach (self::ROLE_PRIORITY as $role) {
            if ($user->hasRole($role)) {
                return $role;
            }
        }

        return 'member';
    }

    private function developerDashboard(User $user): View
    {
        return view('dashboard.developer', [
            'user' => $user,
            'appEnv' => app()->environment(),
            'appDebug' => (bool) config('app.debug'),
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
        ]);
    }

    private function ownerDashboard(User $user): View
    {
        return view('dashboard.owner', [
            'user' => $user,
            'qualificationRulesCount' => QualificationRule::count(),
            'totalMembers' => User::count(),
            'activeMemberships' => Membership::where('status', 'active')->count(),
            'totalMatches' => MatchEvent::count(),
            'totalScores' => Score::count(),
            'settings' => $this->settingsService->all(),
            'feeTiers' => \App\Models\MembershipFeeTier::active()->ordered()->get(),
        ]);
    }

    private function adminDashboard(User $user): View
    {
        return view('dashboard.admin', [
            'user' => $user,
            'pendingMemberships' => Membership::where('status', 'pending')->count(),
            'upcomingMatches' => MatchEvent::where('match_date', '>=', Carbon::today())->count(),
            'pendingScores' => Score::where('status', 'pending')->count(),
            'pendingApprovals' => ApprovalController::totalPendingCount(),
            'recentAuditLogs' => AuditLog::with('user')->latest('created_at')->limit(5)->get(),
        ]);
    }

    private function matchDirectorDashboard(User $user): View
    {
        return view('dashboard.match-director', [
            'user' => $user,
            'myMatchesCount' => MatchEvent::where('created_by', $user->id)->count(),
            'myUpcomingMatches' => MatchEvent::where('created_by', $user->id)
                ->where('match_date', '>=', Carbon::today())->count(),
            'pendingRegistrations' => MatchRegistration::whereIn(
                'match_id',
                MatchEvent::where('created_by', $user->id)->pluck('id')
            )->where('registration_status', 'pending')->count(),
        ]);
    }

    private function provincialAdminDashboard(User $user): View
    {
        $committeePositions = $user->committeePositions()
            ->where('is_active', true)
            ->with('province')
            ->get();

        $provinceIds = $committeePositions->pluck('province_id')->unique()->values()->all();

        $provincialMembersCount = User::whereIn('province_id', $provinceIds)->count();

        $provincialActiveMembersCount = User::whereIn('province_id', $provinceIds)
            ->whereHas('membership', fn ($q) => $q->where('status', 'active'))
            ->count();

        $upcomingProvincialMatches = MatchEvent::whereIn('province_id', $provinceIds)
            ->where('match_date', '>=', Carbon::today())
            ->orderBy('match_date')
            ->limit(5)
            ->get();

        return view('dashboard.provincial-admin', [
            'user' => $user,
            'committeePositions' => $committeePositions,
            'provincialMembersCount' => $provincialMembersCount,
            'provincialActiveMembersCount' => $provincialActiveMembersCount,
            'upcomingProvincialMatches' => $upcomingProvincialMatches,
        ]);
    }

    private function memberDashboard(User $user): View
    {
        $membership = $user->membership;
        $season = (string) Carbon::now()->year;

        $nextMatch = MatchEvent::where('match_date', '>=', Carbon::today())
            ->where('status', 'open')
            ->orderBy('match_date')
            ->first();

        $standingsPosition = Standing::where('user_id', $user->id)
            ->whereNull('province_id')
            ->where('season', $season)
            ->orderBy('points', 'desc')
            ->value('rank');

        $qualificationProgress = $this->qualificationService->getDashboardProgress($user, $season);

        // National + provincial rankings for the season, one row per series
        // the shooter placed in, with a per-division breakdown (Open,
        // Factory, Senior, Ladies, ...) so the shooter can see at a glance
        // where they stand in every cohort they've competed in. Shared with
        // the public shooter profile page via the service so both views
        // always agree.
        $seasonRankings = $this->shooterStandingsSummary->build($user, $season);

        $seasonScores = $user->scores()
            ->whereHas('match', fn ($q) => $q->whereYear('match_date', $season))
            ->with('match')
            ->get();

        $matchesShot = $seasonScores->count();
        $bestPlacement = $seasonScores->whereNotNull('placement')->min('placement');
        $avgPlacement = $seasonScores->whereNotNull('placement')->avg('placement');
        $totalPoints = Standing::where('user_id', $user->id)
            ->whereNull('province_id')
            ->where('season', $season)
            ->sum('points');

        // Per-discipline (PRS / PR22) × per-level (provincial / national)
        // breakdown. Users have consistently asked for these to be split out
        // rather than aggregated into a single row of numbers.
        $scoresByCategory = $seasonScores->groupBy(fn ($s) => ($s->match?->match_type ?? 'unknown').'|'.($s->match?->series_level ?? 'unknown'));

        $standingsByCategory = Standing::where('user_id', $user->id)
            ->where('season', $season)
            ->whereNull('division_id')
            ->get()
            ->groupBy(fn ($st) => $st->series.'|'.($st->province_id ? 'provincial' : 'national'));

        $statsBreakdown = [];
        foreach (['PRS', 'PR22'] as $seriesKey) {
            foreach (['provincial', 'national'] as $levelKey) {
                $key = "{$seriesKey}|{$levelKey}";
                $catScores = $scoresByCategory->get($key) ?? collect();
                $catStandings = $standingsByCategory->get($key) ?? collect();
                $placed = $catScores->whereNotNull('placement');

                $statsBreakdown[] = [
                    'series' => $seriesKey,
                    'level' => $levelKey,
                    'matches' => $catScores->count(),
                    'best' => $placed->min('placement'),
                    'avg' => $placed->count() > 0 ? round($placed->avg('placement'), 1) : null,
                    'points' => (int) $catStandings->sum('points'),
                ];
            }
        }

        $rifles = RifleConfiguration::forUser($user->id)
            ->active()
            ->with(['make', 'model', 'calibre'])
            ->withCount('registrations')
            ->orderByDesc('is_primary')
            ->limit(3)
            ->get();

        $rifleCount = RifleConfiguration::forUser($user->id)->active()->count();

        // Order past matches chronologically (most recent match first) so
        // the shooter sees their season in the order they actually shot it,
        // not in upload/import order. Uses a correlated subquery on the
        // matches table so the 10-row cap picks the newest-shot matches
        // rather than the 10 most recently uploaded scores.
        $recentMatches = $user->scores()
            ->with(['match.province'])
            ->orderByDesc(
                MatchEvent::select('match_date')
                    ->whereColumn('matches.id', 'scores.match_id')
                    ->limit(1)
            )
            ->limit(10)
            ->get();

        return view('dashboard.member', [
            'user' => $user,
            'membership' => $membership,
            'nextMatch' => $nextMatch,
            'scoresCount' => $user->scores()->count(),
            'standingsPosition' => $standingsPosition,
            'qualificationProgress' => $qualificationProgress,
            'matchesShot' => $matchesShot,
            'bestPlacement' => $bestPlacement,
            'avgPlacement' => $avgPlacement ? round($avgPlacement, 1) : null,
            'totalPoints' => $totalPoints,
            'statsBreakdown' => $statsBreakdown,
            'rifles' => $rifles,
            'rifleCount' => $rifleCount,
            'recentMatches' => $recentMatches,
            'seasonRankings' => $seasonRankings,
        ]);
    }
}
