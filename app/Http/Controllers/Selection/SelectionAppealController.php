<?php

namespace App\Http\Controllers\Selection;

use App\Http\Controllers\Controller;
use App\Models\SelectionAppeal;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SelectionAppealController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function store(Request $request, SelectionCycle $cycle, SelectionAthlete $athlete): RedirectResponse
    {
        Gate::authorize('create', SelectionAppeal::class);
        abort_unless($athlete->selection_cycle_id === $cycle->id, 404);

        $data = $request->validate([
            'lodged_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:8000'],
            'fee_paid_at' => ['nullable', 'date'],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $appeal = SelectionAppeal::create([
            'selection_athlete_id' => $athlete->id,
            'lodged_at' => $data['lodged_at'],
            'reason' => $data['reason'],
            'fee_paid_at' => $data['fee_paid_at'] ?? null,
            'fee_amount' => $data['fee_amount'] ?? 5000.00,
            'outcome' => SelectionAppeal::OUTCOME_PENDING,
        ]);

        $this->audit->log(
            $request->user(),
            'selection_appeal_lodged',
            'SelectionAppeal',
            $appeal->id,
            null,
            $appeal->only(['lodged_at', 'fee_amount']),
            "Appeal lodged for athlete #{$athlete->id}",
        );

        return redirect()->route('selection.cycles.athletes.show', [$cycle, $athlete])
            ->with('success', 'Appeal recorded.');
    }

    public function decide(Request $request, SelectionCycle $cycle, SelectionAthlete $athlete, SelectionAppeal $appeal): RedirectResponse
    {
        Gate::authorize('decide', $appeal);
        abort_unless($athlete->selection_cycle_id === $cycle->id && $appeal->selection_athlete_id === $athlete->id, 404);

        $data = $request->validate([
            'outcome' => ['required', Rule::in([
                SelectionAppeal::OUTCOME_UPHELD,
                SelectionAppeal::OUTCOME_DISMISSED,
                SelectionAppeal::OUTCOME_WITHDRAWN,
            ])],
            'refund_issued_at' => ['nullable', 'date'],
        ]);

        $old = $appeal->only(['outcome', 'refund_issued_at']);
        $appeal->update([
            'outcome' => $data['outcome'],
            'refund_issued_at' => $data['refund_issued_at'] ?? null,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        $this->audit->log(
            $request->user(),
            'selection_appeal_decided',
            'SelectionAppeal',
            $appeal->id,
            $old,
            $appeal->only(['outcome', 'refund_issued_at']),
            "Appeal decided: {$data['outcome']}",
        );

        return redirect()->route('selection.cycles.athletes.show', [$cycle, $athlete])
            ->with('success', "Appeal {$data['outcome']}.");
    }
}
