<?php

namespace App\Http\Controllers\Selection;

use App\Http\Controllers\Controller;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionWaiver;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SelectionWaiverController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function store(Request $request, SelectionCycle $cycle, SelectionAthlete $athlete): RedirectResponse
    {
        Gate::authorize('create', SelectionWaiver::class);
        abort_unless($athlete->selection_cycle_id === $cycle->id, 404);

        $data = $request->validate([
            'waived_rule_id' => ['required', Rule::in(['PART-01', 'PART-02', 'PART-03', 'PART-04', 'PART-05'])],
            'request_text' => ['nullable', 'string', 'max:4000'],
            'request_file' => ['nullable', 'file', 'max:5120'],
        ]);

        $filePath = null;
        if ($request->hasFile('request_file')) {
            $filePath = $request->file('request_file')->store('selection/waivers', 'local');
        }

        $waiver = SelectionWaiver::create([
            'selection_athlete_id' => $athlete->id,
            'waived_rule_id' => $data['waived_rule_id'],
            'request_text' => $data['request_text'] ?? null,
            'request_file_path' => $filePath,
            'outcome' => SelectionWaiver::OUTCOME_PENDING,
        ]);

        $this->audit->log(
            $request->user(),
            'selection_waiver_submitted',
            'SelectionWaiver',
            $waiver->id,
            null,
            $waiver->only(['selection_athlete_id', 'waived_rule_id']),
            "Waiver requested for rule {$data['waived_rule_id']}",
        );

        return redirect()->route('selection.cycles.athletes.show', [$cycle, $athlete])
            ->with('success', 'Waiver request recorded.');
    }

    public function decide(Request $request, SelectionCycle $cycle, SelectionAthlete $athlete, SelectionWaiver $waiver): RedirectResponse
    {
        Gate::authorize('decide', $waiver);
        abort_unless($athlete->selection_cycle_id === $cycle->id && $waiver->selection_athlete_id === $athlete->id, 404);

        $data = $request->validate([
            'outcome' => ['required', Rule::in([SelectionWaiver::OUTCOME_GRANTED, SelectionWaiver::OUTCOME_DENIED])],
            'response_text' => ['nullable', 'string', 'max:4000'],
        ]);

        $old = $waiver->only(['outcome', 'response_text']);
        $waiver->update([
            'outcome' => $data['outcome'],
            'response_text' => $data['response_text'] ?? null,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        $this->audit->log(
            $request->user(),
            'selection_waiver_decided',
            'SelectionWaiver',
            $waiver->id,
            $old,
            $waiver->only(['outcome', 'response_text']),
            "Waiver {$waiver->waived_rule_id} decided: {$data['outcome']}",
        );

        return redirect()->route('selection.cycles.athletes.show', [$cycle, $athlete])
            ->with('success', "Waiver marked {$data['outcome']}.");
    }

    public function download(SelectionCycle $cycle, SelectionAthlete $athlete, SelectionWaiver $waiver)
    {
        Gate::authorize('view', $waiver);
        abort_unless($athlete->selection_cycle_id === $cycle->id && $waiver->selection_athlete_id === $athlete->id, 404);
        abort_unless($waiver->request_file_path && Storage::disk('local')->exists($waiver->request_file_path), 404);

        return Storage::disk('local')->download($waiver->request_file_path);
    }
}
