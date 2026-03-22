<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    private const REQUIRED_FIELDS = ['sa_id_number', 'date_of_birth', 'province_id'];

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

        return $next($request);
    }
}
