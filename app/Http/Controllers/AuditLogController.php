<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $category = $request->input('category');
        $revealImpersonation = (bool) $request->user()?->hasRole('developer');

        $auditLogs = AuditLog::query()
            ->with(['user', 'impersonatedUser'])
            ->when(! $revealImpersonation, fn ($q) => $q->hideImpersonationEvents())
            ->visibleAsActorType($category, $revealImpersonation)
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        // Bulk-resolve User/Membership subjects for this page so the "who was
        // changed?" column can render without an N+1 query.
        AuditLog::preloadSubjects($auditLogs->getCollection());

        // Tally per actor category for the filter tabs. Shared viewers
        // see impersonated writes as user changes, matching the badges.
        $counts = AuditLog::query()
            ->when(! $revealImpersonation, fn ($q) => $q->hideImpersonationEvents());

        if ($revealImpersonation) {
            $counts = $counts
                ->selectRaw('actor_type, COUNT(*) as aggregate')
                ->groupBy('actor_type')
                ->pluck('aggregate', 'actor_type');
        } else {
            $bucket = "CASE WHEN impersonated_user_id IS NOT NULL THEN 'user' ELSE COALESCE(actor_type, 'user') END";
            $counts = $counts
                ->selectRaw("{$bucket} as actor_bucket, COUNT(*) as aggregate")
                ->groupByRaw($bucket)
                ->pluck('aggregate', 'actor_bucket');
        }

        return view('audit-logs.index', compact('auditLogs', 'category', 'counts', 'revealImpersonation'));
    }

    public function show(Request $request, AuditLog $auditLog): View
    {
        $revealImpersonation = (bool) $request->user()?->hasRole('developer');

        // Start/stop rows are a developer-only paper trail. 404 rather
        // than 403 so the URL itself does not confirm the event exists.
        abort_if($auditLog->isImpersonationEvent() && ! $revealImpersonation, 404);

        $auditLog->load(['user', 'impersonatedUser']);

        // Resolve the affected subject (member/membership) up front so the
        // view can render its details next to the entity ID.
        $subject = $auditLog->resolveSubject();

        return view('audit-logs.show', compact('auditLog', 'subject', 'revealImpersonation'));
    }
}
