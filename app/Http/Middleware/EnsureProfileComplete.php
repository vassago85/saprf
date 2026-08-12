<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    /**
     * Fields a paid-up member must supply for SASCOC reporting. Identity number
     * (SA ID or passport) and the tri-state boolean are checked separately
     * below because "either/or" and "false is valid" don't fit an empty() loop.
     */
    private const REQUIRED_FIELDS = ['date_of_birth', 'province_id', 'gender', 'ethnicity', 'club_id', 'country_of_residence'];

    private const EXEMPT_ROUTES = [
        'profile',
        'profile.update',
        'logout',
        'verification.notice',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        $membership = $user->membership;

        if (! $membership || $membership->status !== 'active' || $membership->payment_status !== 'paid') {
            return $next($request);
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($user->{$field})) {
                return redirect()->route('profile')
                    ->with('profile_incomplete', true);
            }
        }

        // Identity: a 13-digit SA ID or, for non-citizens, a passport number.
        if (empty($user->sa_id_number) && empty($user->passport_number)) {
            return redirect()->route('profile')
                ->with('profile_incomplete', true);
        }

        // Tri-state booleans where false is a valid, complete answer — only a
        // null means it's still unanswered.
        if (is_null($user->previously_disadvantaged) || is_null($user->sa_citizen)) {
            return redirect()->route('profile')
                ->with('profile_incomplete', true);
        }

        return $next($request);
    }
}
