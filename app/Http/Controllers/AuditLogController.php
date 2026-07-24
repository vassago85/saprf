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
            ->with('user')
            ->actorType($category)
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        // Tally per actor category for the filter tabs.
        $counts = AuditLog::query()
            ->selectRaw('actor_type, COUNT(*) as aggregate')
            ->groupBy('actor_type')
            ->pluck('aggregate', 'actor_type');

        return view('audit-logs.index', compact('auditLogs', 'category', 'counts'));
    }

    public function show(AuditLog $auditLog): View
    {
        $auditLog->load('user');

        return view('audit-logs.show', compact('auditLog'));
    }
}
