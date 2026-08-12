<?php

namespace App\Http\Controllers\Selection;

use App\Http\Controllers\Controller;
use App\Models\SelectionCycle;
use App\Services\AuditLogService;
use App\Services\Selection\SelectionCycleReevaluationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SelectionEvaluationController extends Controller
{
    public function __construct(
        private readonly SelectionCycleReevaluationService $service,
        private readonly AuditLogService $audit,
    ) {}

    public function run(Request $request, SelectionCycle $cycle): RedirectResponse
    {
        Gate::authorize('reevaluate', $cycle);

        $summary = $this->service->run($cycle);

        $this->audit->log(
            $request->user(),
            'selection_cycle_reevaluated',
            'SelectionCycle',
            $cycle->id,
            null,
            $summary,
            "Re-evaluated {$summary['athletes']} athletes in cycle {$cycle->series} {$cycle->season}",
        );

        return redirect()->route('selection.cycles.show', $cycle)
            ->with('success', "Re-evaluated {$summary['athletes']} athletes.");
    }
}
