<?php

namespace App\Http\Middleware;

use App\Support\SidebarNavigation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a staff user opens a page that belongs exclusively to the other
 * View As context, flip the session so the sidebar matches the page.
 *
 * Shared routes (dashboard, events, standings, documents, …) and dual-
 * purpose routes (registrations) are left alone. Role middleware still
 * owns authorisation — this only updates navigation context.
 */
class SyncViewModeWithRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canSwitchViewMode() || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $context = SidebarNavigation::exclusiveContextForRoute($request->route()?->getName());

        if ($context === null || $context === $user->effectiveViewMode()) {
            return $next($request);
        }

        $request->session()->put('view_mode', $context);

        return $next($request);
    }
}
