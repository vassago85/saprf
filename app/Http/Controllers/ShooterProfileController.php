<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\User;
use App\Services\ShooterProfileService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public shooter profile at /shooters/{saprfNumber}.
 *
 * Stable, POPIA-aware URLs keyed on the SAPRF membership number (not the
 * internal user ID), so profile links survive name changes and don't leak
 * primary keys. Season-less form renders the current season under the
 * career shell; explicit season form renders that season under the same
 * shell — season is a tab, not a separate page structure.
 */
class ShooterProfileController extends Controller
{
    public function __construct(
        private readonly ShooterProfileService $shooterProfile,
    ) {}

    /**
     * Route handler for both /shooters/{saprfNumber} and
     * /shooters/{saprfNumber}/{season}. Renders the career hub view
     * (Protea colours + career totals + season switcher) with the
     * requested season's detail loaded inline.
     */
    public function show(Request $request, string $saprfNumber, ?string $season = null): View
    {
        $user = $this->resolveShooter($saprfNumber);

        // Visibility gate. Returns 404 for guests on hidden profiles and
        // for members-only when the viewer is unauthenticated. Owner and
        // staff always pass regardless of the shooter's preference.
        abort_unless($user->isProfileVisibleTo($request->user()), 404);

        $season ??= (string) now()->year;

        // Union of season detail (existing per-match cards + tables) and
        // career-level data (Protea awards, career totals, available
        // seasons). Both live on ShooterProfileService so the same
        // service call feeds any future consumers (season-only iCal
        // export, API endpoint, etc.).
        $payload = $this->shooterProfile->season($user, $season)
            + $this->shooterProfile->career($user);

        return view('shooters.profile', $payload);
    }

    /**
     * Look up a shooter by SAPRF membership number. 404 when no such
     * number exists or when the linked user has been soft-deleted.
     */
    private function resolveShooter(string $saprfNumber): User
    {
        $membership = Membership::query()
            ->where('saprf_number', $saprfNumber)
            ->first();

        abort_if($membership === null, 404);

        $user = $membership->user;

        abort_if($user === null, 404);

        return $user;
    }
}
