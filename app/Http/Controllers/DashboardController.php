<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\QualificationRule;
use App\Models\RifleConfiguration;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\QualificationService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private QualificationService $qualificationService,
        private SettingsService $settingsService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $viewAs = $request->input('view_as');

        $canSwitch = app()->isLocal() || $user->hasRole('owner');

        // Everyone is a member first — admin views are via sidebar nav
        if ($canSwitch && $viewAs && in_array($viewAs, ['owner', 'admin', 'match_director', 'member'])) {
            $dashboard = match ($viewAs) {
                'owner' => $this->ownerDashboard($user),
                'admin' => $this->adminDashboard($user),
                'match_director' => $this->matchDirectorDashboard($user),
                default => $this->memberDashboard($user),
            };
        } else {
            $dashboard = $this->memberDashboard($user);
        }

        $currentRole = $viewAs ?: 'member';

        return $dashboard->with([
            'devSwitcherEnabled' => $canSwitch,
            'currentViewAs' => $currentRole,
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
        ]);
    }

    private function adminDashboard(User $user): View
    {
        return view('dashboard.admin', [
            'user' => $user,
            'pendingMemberships' => Membership::where('status', 'pending')->count(),
            'upcomingMatches' => MatchEvent::where('match_date', '>=', Carbon::today())->count(),
            'pendingScores' => Score::where('status', 'pending')->count(),
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

        $qualificationProgress = [];
        foreach (['PRS', 'PR22'] as $series) {
            $status = $this->qualificationService->getQualificationStatus($user, $series, $season);
            if ($status['required'] > 0) {
                $qualificationProgress[$series] = $status;
            }
        }

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

        $rifles = RifleConfiguration::forUser($user->id)
            ->active()
            ->with(['make', 'model', 'calibre'])
            ->withCount('registrations')
            ->orderByDesc('is_primary')
            ->limit(3)
            ->get();

        $rifleCount = RifleConfiguration::forUser($user->id)->active()->count();

        $recentMatches = $user->scores()
            ->with(['match.province'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('dashboard.member', [
            'user' => $user,
            'membership' => $membership,
            'nextMatch' => $nextMatch,
            'scoresCount' => $user->scores()->count(),
            'standingsPosition' => $standingsPosition,
            'qualificationProgress' => $qualificationProgress ?: null,
            'matchesShot' => $matchesShot,
            'bestPlacement' => $bestPlacement,
            'avgPlacement' => $avgPlacement ? round($avgPlacement, 1) : null,
            'totalPoints' => $totalPoints,
            'rifles' => $rifles,
            'rifleCount' => $rifleCount,
            'recentMatches' => $recentMatches,
        ]);
    }
}
