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

        $auditLogs = AuditLog::query()
            ->with(['user', 'impersonatedUser'])
            ->actorType($category)
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        // Bulk-resolve User/Membership subjects for this page so the "who was
        // changed?" column can render without an N+1 query.
        AuditLog::preloadSubjects($auditLogs->getCollection());

        // Tally per actor category for the filter tabs.
        $counts = AuditLog::query()
            ->selectRaw('actor_type, COUNT(*) as aggregate')
            ->groupBy('actor_type')
            ->pluck('aggregate', 'actor_type');

        return view('audit-logs.index', compact('auditLogs', 'category', 'counts'));
    }

    public function show(AuditLog $auditLog): View
    {
        $auditLog->load(['user', 'impersonatedUser']);

        // Resolve the affected subject (member/membership) up front so the
        // view can render its details next to the entity ID.
        $subject = $auditLog->resolveSubject();

        return view('audit-logs.show', compact('auditLog', 'subject'));
    }
}
