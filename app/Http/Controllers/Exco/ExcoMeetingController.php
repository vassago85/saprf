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
use App\Support\ExcoAiPrompts;
use App\Support\ExcoMeetingImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            'minutesCirculator:id,name',
            'adoptedAtMeeting:id,title,scheduled_at',
            'agendaItems.disciplinaryCase:id,reference,title',
            'actions' => fn ($q) => $q->orderBy('status')->orderBy('due_on'),
            'actions.assignee:id,name',
            'actions.creator:id,name',
            'actions.agendaItem:id,title',
        ]);

        // Sittings that can plausibly adopt these minutes: any other
        // meeting scheduled after this one (regardless of status), so
        // the user can either record retrospective adoption at a past
        // sitting or pin it to the next upcoming meeting.
        $adoptionCandidates = ExcoMeeting::query()
            ->whereKeyNot($meeting->id)
            ->where('scheduled_at', '>=', $meeting->scheduled_at)
            ->orderBy('scheduled_at')
            ->get(['id', 'title', 'scheduled_at']);

        return view('exco.meetings.show', [
            'meeting' => $meeting,
            'visibilities' => ExcoAgendaItemVisibility::cases(),
            'excoUsers' => $this->excoDirectory(),
            'cases' => DisciplinaryCase::query()
                ->orderByDesc('id')
                ->get(['id', 'reference', 'title']),
            'actionStatuses' => ExcoActionStatus::cases(),
            'adoptionCandidates' => $adoptionCandidates,
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

    /**
     * Public reference page for the AI prompts the ExCo workspace
     * relies on (notice → JSON, transcript → minutes). Anyone with
     * access to the ExCo section can view it.
     */
    public function prompts(): View
    {
        return view('exco.prompts.index', [
            'noticeToJson' => ExcoAiPrompts::noticeToJson(),
            'transcriptToMinutes' => ExcoAiPrompts::transcriptToMinutes(),
        ]);
    }

    /**
     * Show the "Import from JSON" form. Meant to be used with the
     * output of the notice→JSON AI prompt.
     */
    public function showImportForm(): View
    {
        return view('exco.meetings.import', [
            'prompt' => ExcoAiPrompts::noticeToJson(),
        ]);
    }

    /**
     * Consume a JSON payload and create a new draft meeting with the
     * supplied agenda items. Errors are returned to the form via
     * Laravel's standard validation flow.
     */
    public function import(Request $request): RedirectResponse
    {
        $raw = (string) $request->input('payload', '');

        try {
            $payload = ExcoMeetingImporter::parse($raw);
            $meeting = ExcoMeetingImporter::createMeeting($payload, $request->user()->id);
        } catch (ValidationException $e) {
            return back()
                ->withErrors($e->errors())
                ->withInput();
        }

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.imported',
            'ExcoMeeting',
            $meeting->id,
            null,
            [
                'title' => $meeting->title,
                'agenda_count' => $meeting->agendaItems()->count(),
            ],
        );

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', 'Meeting imported. Review the agenda below.');
    }

    /**
     * Append agenda items to an already-created meeting (draft or
     * held). Closed meetings are refused — same rule as the manual
     * "add agenda item" form.
     */
    public function importAgenda(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        if ($meeting->isClosed()) {
            return back()->with('error', 'Cannot import agenda items into a closed meeting.');
        }

        $raw = (string) $request->input('payload', '');

        try {
            $payload = ExcoMeetingImporter::parse($raw);
            $inserted = ExcoMeetingImporter::appendAgenda($meeting, $payload);
        } catch (ValidationException $e) {
            return back()
                ->withErrors($e->errors())
                ->withInput();
        }

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', "Imported {$inserted} agenda item(s).");
    }

    /**
     * Printable, sidebar-less minutes view. Members Ctrl+P this and
     * save as PDF to email the record out for approval.
     */
    public function printMinutes(ExcoMeeting $meeting): View
    {
        $meeting->load([
            'creator:id,name',
            'minutesCirculator:id,name',
            'adoptedAtMeeting:id,title,scheduled_at',
            'agendaItems.disciplinaryCase:id,reference,title',
            'actions' => fn ($q) => $q->orderBy('status')->orderBy('due_on'),
            'actions.assignee:id,name',
            'actions.agendaItem:id,title',
        ]);

        return view('exco.meetings.minutes-print', ['meeting' => $meeting]);
    }

    /**
     * Record that the draft minutes have been circulated to ExCo for
     * review. Requires the meeting to be closed — you cannot circulate
     * minutes of a sitting that has not yet ended.
     */
    public function markCirculated(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        if (! $meeting->isClosed()) {
            return back()->with('error', 'Close the meeting before circulating the minutes.');
        }

        $meeting->update([
            'minutes_circulated_at' => now(),
            'minutes_circulated_by' => $request->user()->id,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.minutes_circulated',
            'ExcoMeeting',
            $meeting->id,
            null,
            ['circulated_at' => $meeting->minutes_circulated_at->toIso8601String()],
        );

        return back()->with('success', 'Minutes marked as circulated.');
    }

    /**
     * Record that the circulated minutes were formally adopted at a
     * subsequent sitting. The adopting meeting must also exist in the
     * platform so we keep a two-way audit link.
     */
    public function markAdopted(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        if (! $meeting->minutesAreCirculated()) {
            return back()->with('error', 'Circulate the minutes before recording adoption.');
        }

        $data = $request->validate([
            'adopted_at_meeting_id' => ['required', 'integer', 'exists:exco_meetings,id'],
        ]);

        if ((int) $data['adopted_at_meeting_id'] === $meeting->id) {
            return back()->with('error', 'A meeting cannot adopt its own minutes.');
        }

        $meeting->update([
            'minutes_adopted_at' => now(),
            'minutes_adopted_meeting_id' => (int) $data['adopted_at_meeting_id'],
        ]);

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.minutes_adopted',
            'ExcoMeeting',
            $meeting->id,
            null,
            [
                'adopted_at' => $meeting->minutes_adopted_at->toIso8601String(),
                'adopted_at_meeting_id' => $meeting->minutes_adopted_meeting_id,
            ],
        );

        return back()->with('success', 'Minutes recorded as adopted.');
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
