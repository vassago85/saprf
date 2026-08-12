<?php

namespace App\Http\Controllers\Selection;

use App\Http\Controllers\Controller;
use App\Models\SelectionCycle;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SelectionCycleController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function index(): View
    {
        Gate::authorize('viewAny', SelectionCycle::class);
        $cycles = SelectionCycle::query()
            ->with('activePolicy')
            ->withCount('athletes')
            ->orderByDesc('season')
            ->orderBy('series')
            ->get();

        return view('selection.cycles.index', compact('cycles'));
    }

    public function create(): View
    {
        Gate::authorize('create', SelectionCycle::class);

        return view('selection.cycles.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', SelectionCycle::class);
        $data = $this->validated($request);

        $cycle = SelectionCycle::create($data + [
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'selection_cycle_created',
            'SelectionCycle',
            $cycle->id,
            null,
            $cycle->only(['series', 'season', 'championship_name', 'status']),
            "Selection cycle {$cycle->series} {$cycle->season} created",
        );

        return redirect()
            ->route('selection.cycles.show', $cycle)
            ->with('success', "Cycle {$cycle->series} {$cycle->season} created.");
    }

    public function show(SelectionCycle $cycle): View
    {
        Gate::authorize('view', $cycle);
        $cycle->load(['activePolicy', 'policies']);
        $athleteCounts = $cycle->athletes()
            ->selectRaw('state, COUNT(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        return view('selection.cycles.show', compact('cycle', 'athleteCounts'));
    }

    public function edit(SelectionCycle $cycle): View
    {
        Gate::authorize('update', $cycle);

        return view('selection.cycles.edit', compact('cycle'));
    }

    public function update(Request $request, SelectionCycle $cycle): RedirectResponse
    {
        Gate::authorize('update', $cycle);
        $data = $this->validated($request, $cycle->id);
        $data['status'] = $request->input('status', $cycle->status);

        $old = $cycle->only(['series', 'season', 'status', 'championship_name']);
        $cycle->update($data);

        $this->auditLogService->log(
            $request->user(),
            'selection_cycle_updated',
            'SelectionCycle',
            $cycle->id,
            $old,
            $cycle->only(['series', 'season', 'status', 'championship_name']),
            "Selection cycle {$cycle->series} {$cycle->season} updated",
        );

        return redirect()
            ->route('selection.cycles.show', $cycle)
            ->with('success', 'Cycle updated.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $uniqueRule = Rule::unique('selection_cycles')->where(fn ($q) => $q
            ->where('series', $request->input('series'))
            ->where('season', $request->input('season')));
        if ($ignoreId) {
            $uniqueRule = $uniqueRule->ignore($ignoreId);
        }

        return $request->validate([
            'series' => ['required', Rule::in(['PRS', 'PR22']), $uniqueRule],
            'season' => ['required', 'string', 'max:20'],
            'championship_name' => ['required', 'string', 'max:255'],
            'qualifying_period_start' => ['required', 'date'],
            'qualifying_period_end' => ['required', 'date', 'after_or_equal:qualifying_period_start'],
            'declaration_deadline' => ['required', 'date'],
            'results_freeze' => ['required', 'date'],
            'panel_lock_date' => ['nullable', 'date'],
            'deliberation_start' => ['nullable', 'date'],
            'deliberation_end' => ['nullable', 'date', 'after_or_equal:deliberation_start'],
            'publication_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['draft', 'open', 'frozen', 'announced', 'closed'])],
        ]);
    }
}
