<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate that restricts "member-only" tooling to current paid-up SAPRF
 * members. Non-members are still welcome to register for matches, but can't
 * reach the dashboard-stat heavy pages, the membership certificate, the
 * firearm shooting record, or the kit trackers.
 *
 * The middleware deliberately returns a 403 with a landing page rather than
 * silently redirecting — a shooter who clicked "My Certificate" needs to see
 * why they're bounced, with a clear "Join SAPRF" call-to-action.
 */
class EnsureActiveMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            // The underlying auth middleware will send the shooter to login;
            // we just defer.
            return $next($request);
        }

        if ($user->isActiveMember()) {
            return $next($request);
        }

        // AJAX / JSON callers (Livewire has its own guard, so this is mostly
        // for API-ish endpoints like QR verifies). Return 403 with a hint
        // rather than an HTML page the client wouldn't render.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'This feature is only available to paid-up SAPRF members.',
            ], 403);
        }

        return response()->view('errors.active-member-required', [
            'user' => $user,
        ], 403);
    }
}
