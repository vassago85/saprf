<?php

namespace App\Http\Controllers\Exco;

use App\Enums\ExcoAmendmentStatus;
use App\Http\Controllers\Controller;
use App\Models\ExcoAgendaItem;
use App\Models\ExcoMeeting;
use App\Models\ExcoMinuteAmendment;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Proposed-change workflow for circulated ExCo minutes.
 *
 *   POST /meetings/{meeting}/amendments
 *       Submit a new amendment. Available to any ExCo member while the
 *       meeting is in the "review window" (circulated -> not adopted).
 *
 *   POST /meetings/{meeting}/amendments/{amendment}/resolve
 *       Chair/secretary accepts or rejects a pending amendment. The
 *       actual minutes-text edit for an accepted amendment happens
 *       through the normal agenda-item update flow — the review window
 *       unlocks the minutes field for exactly this purpose.
 *
 *   DELETE /meetings/{meeting}/amendments/{amendment}
 *       Proposer withdraws their own still-pending amendment.
 *
 * Route-gated to `role:developer|exco|chair` in web.php; semantic
 * guards (review-window state, ownership for withdraw) live in the
 * controller so error flashes are user-friendly.
 */
class ExcoMinuteAmendmentController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function store(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        if (! $meeting->isInReviewWindow()) {
            return back()->with('error', 'Amendments can only be proposed while the minutes are circulated and not yet adopted.');
        }

        $data = $request->validate([
            'agenda_item_id' => ['nullable', 'integer'],
            'proposed_text' => ['required', 'string', 'max:5000'],
        ]);

        $agendaItemId = $this->resolveAgendaItemId($meeting, $data['agenda_item_id'] ?? null);

        $amendment = ExcoMinuteAmendment::create([
            'meeting_id' => $meeting->id,
            'agenda_item_id' => $agendaItemId,
            'proposed_by' => $request->user()->id,
            'proposed_text' => $data['proposed_text'],
            'status' => ExcoAmendmentStatus::Pending,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.amendment_proposed',
            'ExcoMinuteAmendment',
            $amendment->id,
            null,
            [
                'meeting_id' => $meeting->id,
                'agenda_item_id' => $agendaItemId,
            ],
        );

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', 'Proposed amendment submitted. The chair will review it.');
    }

    public function resolve(Request $request, ExcoMeeting $meeting, ExcoMinuteAmendment $amendment): RedirectResponse
    {
        $this->ensureBelongsTo($meeting, $amendment);

        if (! $meeting->isInReviewWindow()) {
            return back()->with('error', 'The review window is closed — amendments can no longer be resolved.');
        }

        if (! $amendment->isPending()) {
            return back()->with('error', 'This amendment has already been resolved.');
        }

        $data = $request->validate([
            'decision' => ['required', 'string', 'in:accepted,rejected'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $target = ExcoAmendmentStatus::from($data['decision']);

        $amendment->update([
            'status' => $target,
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'resolution_notes' => $data['notes'] ?? null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.amendment_'.$target->value,
            'ExcoMinuteAmendment',
            $amendment->id,
            null,
            [
                'meeting_id' => $meeting->id,
                'agenda_item_id' => $amendment->agenda_item_id,
                'notes' => $amendment->resolution_notes,
            ],
        );

        $flash = $target === ExcoAmendmentStatus::Accepted
            ? 'Amendment accepted. Edit the affected agenda item to apply the change.'
            : 'Amendment rejected.';

        return redirect()->route('exco.meetings.show', $meeting)->with('success', $flash);
    }

    public function destroy(Request $request, ExcoMeeting $meeting, ExcoMinuteAmendment $amendment): RedirectResponse
    {
        $this->ensureBelongsTo($meeting, $amendment);

        // Proposer can withdraw their own still-pending amendment.
        // Resolved amendments are historical and stay put.
        if ($amendment->proposed_by !== $request->user()->id) {
            return back()->with('error', 'You can only withdraw your own amendments.');
        }

        if (! $amendment->isPending()) {
            return back()->with('error', 'Resolved amendments cannot be withdrawn.');
        }

        $amendment->delete();

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', 'Amendment withdrawn.');
    }

    /**
     * Only accept an `agenda_item_id` that actually belongs to this
     * meeting — a hand-crafted request cannot cross-link an amendment
     * to another sitting's items.
     */
    private function resolveAgendaItemId(ExcoMeeting $meeting, ?int $id): ?int
    {
        if ($id === null || $id === 0) {
            return null;
        }

        return ExcoAgendaItem::query()
            ->where('meeting_id', $meeting->id)
            ->whereKey($id)
            ->value('id');
    }

    private function ensureBelongsTo(ExcoMeeting $meeting, ExcoMinuteAmendment $amendment): void
    {
        abort_unless($amendment->meeting_id === $meeting->id, 404);
    }
}
