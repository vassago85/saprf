<?php

namespace App\Http\Controllers\Selection;

use App\Http\Controllers\Controller;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionDeclaration;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SelectionDeclarationController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function store(Request $request, SelectionCycle $cycle, SelectionAthlete $athlete): RedirectResponse
    {
        Gate::authorize('update', $athlete);
        abort_unless($athlete->selection_cycle_id === $cycle->id, 404);

        $data = $request->validate([
            'submitted_at' => ['required', 'date'],
            'eligibility_to_compete_received' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'signed_form' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
        ]);

        $signedPath = null;
        if ($request->hasFile('signed_form')) {
            $signedPath = $request->file('signed_form')->store('selection/declarations', 'local');
        }

        SelectionDeclaration::updateOrCreate(
            ['selection_athlete_id' => $athlete->id],
            [
                'submitted_at' => $data['submitted_at'],
                'captured_by' => $request->user()->id,
                'form_data' => [
                    'eligibility_to_compete_received' => $request->boolean('eligibility_to_compete_received'),
                    'notes' => $data['notes'] ?? null,
                ],
                'signed_form_path' => $signedPath ?: optional($athlete->declaration)->signed_form_path,
                'status' => SelectionDeclaration::STATUS_SUBMITTED,
            ],
        );

        $this->audit->log(
            $request->user(),
            'selection_declaration_captured',
            'SelectionAthlete',
            $athlete->id,
            null,
            ['submitted_at' => $data['submitted_at']],
            "Captured declaration for athlete #{$athlete->id}",
        );

        return redirect()->route('selection.cycles.athletes.show', [$cycle, $athlete])
            ->with('success', 'Declaration captured.');
    }
}
