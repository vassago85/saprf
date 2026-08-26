<?php

namespace App\Http\Controllers\Exco;

use App\Enums\ExcoActionStatus;
use App\Enums\ExcoAgendaItemVisibility;
use App\Enums\ExcoMeetingStatus;
use App\Enums\ExcoMeetingType;
use App\Http\Controllers\Controller;
use App\Models\DisciplinaryCase;
use App\Models\ExcoMeeting;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ExCo meeting CRUD + status transitions. The `show` view is the
 * working page during a sitting — agenda items, briefings, minutes,
 * and follow-up actions all live there.
 *
 * Route-gated to `role:developer|exco|chair`. Owner and admin, though
 * senior, do not see the ExCo workspace: it can hold personal
 * disciplinary information and the sidebar link is hidden from them
 * for the same reason.
 */
class ExcoMeetingController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        $upcoming = ExcoMeeting::query()
            ->with('creator:id,name')
            ->where('status', '!=', ExcoMeetingStatus::Closed)
            ->orderBy('scheduled_at')
            ->get();

        $past = ExcoMeeting::query()
            ->with('creator:id,name')
            ->where('status', ExcoMeetingStatus::Closed)
            ->orderByDesc('scheduled_at')
            ->paginate(20);

        return view('exco.meetings.index', [
            'upcoming' => $upcoming,
            'past' => $past,
        ]);
    }

    public function create(): View
    {
        return view('exco.meetings.form', [
            'meeting' => null,
            'types' => ExcoMeetingType::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $meeting = ExcoMeeting::create([
            'title' => $data['title'],
            'type' => $data['type'],
            'scheduled_at' => $data['scheduled_at'],
            'location' => $data['location'] ?? null,
            'attendance_notes' => $data['attendance_notes'] ?? null,
            'status' => ExcoMeetingStatus::Draft,
            'created_by' => $request->user()->id,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.created',
            'ExcoMeeting',
            $meeting->id,
            null,
            ['title' => $meeting->title, 'scheduled_at' => $meeting->scheduled_at->toIso8601String()],
        );

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', 'Meeting created. Build the agenda below.');
    }

    public function show(ExcoMeeting $meeting): View
    {
        $meeting->load([
            'creator:id,name',
            'agendaItems.disciplinaryCase:id,reference,title',
            'actions' => fn ($q) => $q->orderBy('status')->orderBy('due_on'),
            'actions.assignee:id,name',
            'actions.creator:id,name',
            'actions.agendaItem:id,title',
        ]);

        return view('exco.meetings.show', [
            'meeting' => $meeting,
            'visibilities' => ExcoAgendaItemVisibility::cases(),
            'excoUsers' => $this->excoDirectory(),
            'cases' => DisciplinaryCase::query()
                ->orderByDesc('id')
                ->get(['id', 'reference', 'title']),
            'actionStatuses' => ExcoActionStatus::cases(),
        ]);
    }

    public function edit(ExcoMeeting $meeting): View
    {
        return view('exco.meetings.form', [
            'meeting' => $meeting,
            'types' => ExcoMeetingType::cases(),
        ]);
    }

    public function update(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        if ($meeting->isClosed()) {
            return back()->with('error', 'Closed meetings cannot be edited.');
        }

        $data = $this->validated($request);
        $original = $meeting->only(['title', 'type', 'scheduled_at', 'location']);

        $meeting->update([
            'title' => $data['title'],
            'type' => $data['type'],
            'scheduled_at' => $data['scheduled_at'],
            'location' => $data['location'] ?? null,
            'attendance_notes' => $data['attendance_notes'] ?? null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.updated',
            'ExcoMeeting',
            $meeting->id,
            $original,
            $meeting->only(['title', 'type', 'scheduled_at', 'location']),
        );

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', 'Meeting updated.');
    }

    /**
     * Delete a meeting. Only draft meetings can be deleted so that the
     * historical record of held/closed sittings is preserved.
     */
    public function destroy(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        if (! $meeting->isDraft()) {
            return back()->with('error', 'Only draft meetings can be deleted. Close it instead.');
        }

        $snapshot = [
            'title' => $meeting->title,
            'scheduled_at' => $meeting->scheduled_at->toIso8601String(),
        ];

        $meeting->delete();

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.deleted',
            'ExcoMeeting',
            $meeting->id,
            $snapshot,
            null,
        );

        return redirect()->route('exco.meetings.index')
            ->with('success', 'Meeting deleted.');
    }

    /**
     * Move a draft meeting forward to `held` when minutes start being
     * captured, or close a held meeting once nothing further will be
     * recorded. Two directions only; re-opening a closed meeting is a
     * deliberate policy decision we do not automate.
     */
    public function transition(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:held,closed'],
        ]);

        $target = ExcoMeetingStatus::from($data['status']);

        $ok = ($target === ExcoMeetingStatus::Held && $meeting->status === ExcoMeetingStatus::Draft)
            || ($target === ExcoMeetingStatus::Closed && $meeting->status === ExcoMeetingStatus::Held);

        if (! $ok) {
            return back()->with('error', 'That status change is not allowed from the current state.');
        }

        $original = $meeting->status;
        $meeting->update(['status' => $target]);

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.status_changed',
            'ExcoMeeting',
            $meeting->id,
            ['status' => $original->value],
            ['status' => $target->value],
        );

        $flash = match ($target) {
            ExcoMeetingStatus::Held => 'Meeting marked as in progress. Capture minutes as you go.',
            ExcoMeetingStatus::Closed => 'Meeting closed.',
            default => 'Status updated.',
        };

        return redirect()->route('exco.meetings.show', $meeting)->with('success', $flash);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', 'string', 'in:' . implode(',', array_column(ExcoMeetingType::cases(), 'value'))],
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:200'],
            'attendance_notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * ExCo/Chair members eligible to own an action item or be listed as
     * present. Deliberately not cached — the list is tiny.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function excoDirectory()
    {
        return User::query()
            ->role(['exco', 'chair', 'developer'])
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
