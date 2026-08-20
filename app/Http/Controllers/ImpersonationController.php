<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Developer-only user impersonation.
 *
 * Lets a signed-in developer temporarily assume any other member's
 * identity so they can see and interact with the app exactly as that
 * member does — the intended use is debugging support tickets ("this
 * member can't see their certificate", "the payment button is missing
 * for this junior account", etc.).
 *
 * Guarantees:
 *   - Only users holding the `developer` role can START an impersonation.
 *   - Every start/stop is written to the audit log via AuditLogService so
 *     POPIA subject-access requests can show every time a staff member
 *     assumed a member's identity. Writes made *during* impersonation
 *     are re-attributed to the developer (user_id) with the assumed
 *     member stored on impersonated_user_id.
 *   - The developer's original user ID lives in the session under
 *     `impersonator_id` — that key is what the layout banner and the
 *     Stop route both read from. When it's absent, the app is in normal
 *     mode; when present, the app is in impersonation mode.
 *   - The stop route is NOT gated on `developer` role, because at the
 *     moment we hit Stop we're logged in as the TARGET, not the developer.
 *     Instead it silently no-ops when `impersonator_id` is missing.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function start(Request $request, User $user): RedirectResponse
    {
        $developer = $request->user();

        // Belt-and-braces: the route middleware already gates on
        // role:developer, but a second explicit check keeps the action
        // safe even if someone later rewires the route group.
        abort_unless($developer && $developer->hasRole('developer'), 403);

        // Self-impersonation would look like a successful assumption but
        // leave the "Return to yourself" banner permanently visible.
        // Cheaper to refuse than to defend later.
        if ($developer->is($user)) {
            return redirect()->route('dashboard')
                ->with('error', 'You cannot impersonate yourself.');
        }

        // If already impersonating someone else, clear the previous
        // session key silently. Chained impersonations (A → B → C)
        // would leave the developer unable to return to themselves.
        $request->session()->pull('impersonator_id');
        $request->session()->pull('impersonator_name');

        $this->auditLog->log(
            $developer,
            'impersonation.started',
            'User',
            $user->id,
            null,
            [
                'target_user_id' => $user->id,
                'target_name' => $user->name,
                'target_email' => $user->email,
            ],
            "{$developer->name} started impersonating {$user->name} (ID {$user->id})",
        );

        // Persist the developer's identity BEFORE we switch auth — once
        // we call Auth::loginUsingId the session's user flips to the
        // target and $request->user() would no longer be the developer.
        $request->session()->put('impersonator_id', $developer->id);
        $request->session()->put('impersonator_name', $developer->name);

        // Session-only login (no remember token). Impersonation ends
        // when the developer closes the browser, hits Stop, or the
        // session times out — never leaks past that.
        Auth::loginUsingId($user->id, false);

        return redirect()->route('dashboard')
            ->with('success', "You are now viewing SAPRF as {$user->name}.");
    }

    public function stop(Request $request): RedirectResponse
    {
        $originalId = $request->session()->pull('impersonator_id');
        $request->session()->forget('impersonator_name');

        if (! $originalId) {
            // No impersonation active — someone clicked the banner in a
            // stale tab. Send them home rather than 404.
            return redirect()->route('dashboard');
        }

        // Snapshot the target before we switch back, so the audit
        // record shows who was being impersonated, not who ran the stop.
        $target = $request->user();

        Auth::loginUsingId($originalId, false);

        $developer = $request->user();

        $this->auditLog->log(
            $developer,
            'impersonation.stopped',
            'User',
            $target?->id,
            null,
            [
                'target_user_id' => $target?->id,
                'target_name' => $target?->name,
            ],
            "{$developer?->name} stopped impersonating {$target?->name}",
        );

        return redirect()->route('dashboard')
            ->with('success', "Returned to yourself ({$developer?->name}).");
    }
}
