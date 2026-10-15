<?php

namespace App\Http\Controllers;

use App\Models\NationalTeamAppearance;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public "Represented South Africa" roster at /sa-team.
 *
 * Lists every national-team appearance grouped by year, with each shooter's
 * division and placing. Powered entirely by the admin-maintained
 * `national_team_appearances` table — Exco adds entries via
 * `/national-team` and they appear here automatically.
 *
 * POPIA note: appearances for juniors flagged `members_only` are hidden
 * from guest viewers (parity with the shooter profile visibility gate).
 * `hidden` users never show up regardless.
 */
class PublicNationalTeamController extends Controller
{
    public function index(Request $request): View
    {
        $viewer = $request->user();

        $appearances = NationalTeamAppearance::query()
            ->with(['user.membership', 'user.province', 'division'])
            ->orderByDesc('year')
            ->orderBy('championship_name')
            ->orderBy('id')
            ->get();

        // Visibility gate — mirror ShooterProfileController::show semantics
        // so a junior whose profile is members-only is also hidden from the
        // roster for guests. Owner / staff see everything.
        $visibleAppearances = $appearances->filter(function (NationalTeamAppearance $appearance) use ($viewer): bool {
            $user = $appearance->user;
            if ($user === null) {
                return false;
            }

            return $user->isProfileVisibleTo($viewer);
        })->values();

        $groupedByYear = $visibleAppearances->groupBy(fn (NationalTeamAppearance $a) => (int) $a->year);

        return view('national-team.public', [
            'groupedByYear' => $groupedByYear,
            'totalAppearances' => $visibleAppearances->count(),
            'uniqueShooters' => $visibleAppearances->pluck('user_id')->unique()->count(),
            'coloursHolders' => $visibleAppearances
                ->where('awarded_colours', true)
                ->pluck('user_id')
                ->unique()
                ->count(),
            'yearsCovered' => $groupedByYear->keys()->sortDesc()->values(),
        ]);
    }
}
