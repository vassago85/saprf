<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Dashboard\AdminDashboardPresenter;
use App\Support\Dashboard\MatchDirectorDashboardPresenter;
use App\Support\Dashboard\OwnerDashboardPresenter;
use App\Support\Dashboard\ProvincialAdminDashboardPresenter;
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
        private OwnerDashboardPresenter $ownerPresenter,
        private AdminDashboardPresenter $adminPresenter,
        private MatchDirectorDashboardPresenter $matchDirectorPresenter,
        private ProvincialAdminDashboardPresenter $provincialAdminPresenter,
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
            'exco' => $this->ownerDashboard($user, $request),
            'owner' => $this->ownerDashboard($user, $request),
            'admin' => $this->adminDashboard($user, $request),
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

    private function ownerDashboard(User $user, Request $request): View
    {
        return view('dashboard.owner', [
            'user' => $user,
            'payload' => $this->ownerPresenter->build($user, $request->input('season')),
        ]);
    }

    private function adminDashboard(User $user, Request $request): View
    {
        return view('dashboard.admin', [
            'user' => $user,
            'payload' => $this->adminPresenter->build($user, $request->input('season')),
        ]);
    }

    private function matchDirectorDashboard(User $user): View
    {
        return view('dashboard.match-director', [
            'user' => $user,
            'payload' => $this->matchDirectorPresenter->build($user),
        ]);
    }

    private function provincialAdminDashboard(User $user): View
    {
        return view('dashboard.provincial-admin', [
            'user' => $user,
            'payload' => $this->provincialAdminPresenter->build($user),
        ]);
    }

    private function memberDashboard(User $user): View
    {
        return view('dashboard.member', [
            'user' => $user,
        ]);
    }
}
