<?php

namespace App\Http\Controllers\Selection;

use App\Http\Controllers\Controller;
use App\Models\SelectionCycle;
use App\Models\SelectionPolicy;
use App\Services\AuditLogService;
use App\Services\Selection\PolicyImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class SelectionPolicyController extends Controller
{
    public function __construct(
        private readonly PolicyImportService $service,
        private readonly AuditLogService $audit,
    ) {}

    public function store(Request $request, SelectionCycle $cycle): RedirectResponse
    {
        Gate::authorize('importPolicy', $cycle);

        $data = $request->validate([
            'source' => ['required', 'in:upload,path'],
            'file' => ['required_if:source,upload', 'file', 'mimetypes:application/json,text/plain', 'max:2048'],
            'path' => ['required_if:source,path', 'nullable', 'string', 'max:255'],
        ]);

        if ($data['source'] === 'upload') {
            $stored = $request->file('file')->store('selection/policies', 'local');
            $absPath = Storage::disk('local')->path($stored);
        } else {
            $absPath = base_path(ltrim((string) $data['path'], '/\\'));
        }

        $policy = $this->service->import($absPath, $cycle, $request->user());

        $this->audit->log(
            $request->user(),
            'selection_policy_imported',
            'SelectionPolicy',
            $policy->id,
            null,
            [
                'cycle_id' => $cycle->id,
                'version' => $policy->version,
                'hash' => $policy->source_hash,
            ],
            "Imported selection policy v{$policy->version} into cycle {$cycle->series} {$cycle->season}",
        );

        return redirect()
            ->route('selection.cycles.show', $cycle)
            ->with('success', "Policy version {$policy->version} imported and set active.");
    }

    public function show(SelectionCycle $cycle, SelectionPolicy $policy): \Illuminate\View\View
    {
        Gate::authorize('view', $cycle);
        abort_unless($policy->selection_cycle_id === $cycle->id, 404);

        return view('selection.policies.show', compact('cycle', 'policy'));
    }
}
