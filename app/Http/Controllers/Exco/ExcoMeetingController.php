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
use App\Notifications\MinutesCirculatedNotification;
use App\Services\AuditLogService;
use App\Support\ExcoAiPrompts;
use App\Support\ExcoMeetingImporter;
use App\Support\ExcoMinutesImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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

    public function index(Request $request): View
    {
        // Archived tab: dedicated view of soft-hidden meetings so
        // developers/exco can inspect or restore them without them
        // polluting the day-to-day upcoming/past tables.
        if ($request->query('archived') === '1') {
            $archived = ExcoMeeting::query()
                ->onlyArchived()
                ->with(['creator:id,name', 'archiver:id,name'])
                ->orderByDesc('archived_at')
                ->paginate(20)
                ->withQueryString();

            return view('exco.meetings.index', [
                'archived' => $archived,
                'view' => 'archived',
            ]);
        }

        $upcoming = ExcoMeeting::query()
            ->notArchived()
            ->with('creator:id,name')
            ->where('status', '!=', ExcoMeetingStatus::Closed)
            ->orderBy('scheduled_at')
            ->get();

        $past = ExcoMeeting::query()
            ->notArchived()
            ->with('creator:id,name')
            ->where('status', ExcoMeetingStatus::Closed)
            ->orderByDesc('scheduled_at')
            ->paginate(20);

        return view('exco.meetings.index', [
            'upcoming' => $upcoming,
            'past' => $past,
            'archivedCount' => ExcoMeeting::onlyArchived()->count(),
            'view' => 'active',
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
            'agendaItems.amendments.proposer:id,name',
            'actions' => fn ($q) => $q->orderBy('status')->orderBy('due_on'),
            'actions.assignee:id,name',
            'actions.creator:id,name',
            'actions.agendaItem:id,title',
            'amendments.proposer:id,name',
            'amendments.resolver:id,name',
            'amendments.agendaItem:id,title,sort_order',
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

        // Meeting-aware transcript→minutes prompt: bake the current
        // agenda in so the AI can't drift on titles. Only relevant
        // once minutes are being captured (held / closed) — hidden on
        // drafts by the view.
        $minutesPrompt = ExcoAiPrompts::transcriptToMinutesJson(
            $meeting->agendaItems->map(fn ($item, $i) => [
                'index' => $i + 1,
                'title' => $item->title,
            ])->all(),
        );

        return view('exco.meetings.show', [
            'meeting' => $meeting,
            'visibilities' => ExcoAgendaItemVisibility::cases(),
            'excoUsers' => $this->excoDirectory(),
            'cases' => DisciplinaryCase::query()
                ->orderByDesc('id')
                ->get(['id', 'reference', 'title']),
            'actionStatuses' => ExcoActionStatus::cases(),
            'adoptionCandidates' => $adoptionCandidates,
            'minutesPrompt' => $minutesPrompt,
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
     * Hard-delete a draft or held meeting (test sittings, abandoned
     * sessions). Closed meetings cannot be hard-deleted — the archive()
     * action is the escape hatch there, keeping audit logs and linked
     * action items intact while hiding the row from active views.
     */
    public function destroy(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        if ($meeting->isClosed()) {
            return back()->with('error', 'Closed meetings cannot be hard-deleted. Use "Archive" instead to hide this meeting without losing the audit trail.');
        }

        $snapshot = [
            'title' => $meeting->title,
            'scheduled_at' => $meeting->scheduled_at->toIso8601String(),
            'status' => $meeting->status->value,
            'agenda_count' => $meeting->agendaItems()->count(),
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
     * Soft-hide a closed meeting. Records who archived it and (optionally)
     * why, so any later "where did that go?" question is answerable from
     * the audit log. Reversible via unarchive() — nothing is deleted.
     *
     * Draft/held meetings should use hard delete instead; archive is
     * specifically the escape hatch for a closed sitting that turned out
     * to be a duplicate, a test run, or otherwise shouldn't be part of
     * the active record.
     */
    public function archive(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        // Semantic guards live here (not in the policy) because
        // Gate::before in AppServiceProvider auto-allows every ability
        // for developer/exco, so the policy method is bypassed. Route
        // middleware `role:developer|exco|chair` handles the who; these
        // guards handle the when.
        if (! $meeting->isClosed()) {
            return back()->with('error', 'Only closed meetings can be archived. Use "Delete meeting" for drafts and in-progress sittings.');
        }

        if ($meeting->isArchived()) {
            return back()->with('error', 'This meeting is already archived.');
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $meeting->update([
            'archived_at' => now(),
            'archived_by' => $request->user()->id,
            'archive_reason' => $data['reason'] ?? null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.archived',
            'ExcoMeeting',
            $meeting->id,
            null,
            [
                'archived_at' => $meeting->archived_at->toIso8601String(),
                'reason' => $meeting->archive_reason,
            ],
        );

        return redirect()->route('exco.meetings.index')
            ->with('success', 'Meeting archived. It no longer appears in the active list — find it under Archived.');
    }

    /**
     * Restore an archived meeting to its previous status.
     */
    public function unarchive(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        if (! $meeting->isArchived()) {
            return back()->with('error', 'This meeting is not archived.');
        }

        $snapshot = [
            'archived_at' => $meeting->archived_at?->toIso8601String(),
            'reason' => $meeting->archive_reason,
        ];

        $meeting->update([
            'archived_at' => null,
            'archived_by' => null,
            'archive_reason' => null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.unarchived',
            'ExcoMeeting',
            $meeting->id,
            $snapshot,
            null,
        );

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', 'Meeting restored.');
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
            'transcriptToMinutesJson' => ExcoAiPrompts::transcriptToMinutesJson(),
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
     * Consume a JSON payload with per-item minutes (and optional
     * decisions + actions) produced by the transcript→minutes AI
     * prompt. Applies to draft/held meetings; closed is locked to
     * preserve the historical record.
     */
    public function importMinutes(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        if ($meeting->isClosed()) {
            return back()->with('error', 'Cannot import minutes into a closed meeting.');
        }

        $raw = (string) $request->input('payload', '');

        try {
            $payload = ExcoMinutesImporter::parse($raw);
            $summary = ExcoMinutesImporter::apply($meeting, $payload, $request->user()->id);
        } catch (ValidationException $e) {
            return back()
                ->withErrors($e->errors())
                ->withInput();
        }

        $this->auditLogService->log(
            $request->user(),
            'exco_meeting.minutes_imported',
            'ExcoMeeting',
            $meeting->id,
            null,
            [
                'items_updated' => $summary['items_updated'],
                'actions_created' => $summary['actions_created'],
                'items_skipped' => count($summary['items_skipped']),
            ],
        );

        $flash = sprintf(
            'Imported minutes for %d agenda item%s and created %d action item%s.',
            $summary['items_updated'],
            $summary['items_updated'] === 1 ? '' : 's',
            $summary['actions_created'],
            $summary['actions_created'] === 1 ? '' : 's',
        );

        if ($summary['actions_with_unmatched_owners'] > 0) {
            $flash .= sprintf(
                ' %d action%s had an owner name the platform could not resolve — check the details field.',
                $summary['actions_with_unmatched_owners'],
                $summary['actions_with_unmatched_owners'] === 1 ? '' : 's',
            );
        }

        if ($summary['items_skipped'] !== []) {
            return redirect()->route('exco.meetings.show', $meeting)
                ->with('success', $flash)
                ->with('minutes_import_skipped', $summary['items_skipped']);
        }

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', $flash);
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
            'amendments.proposer:id,name',
            'amendments.resolver:id,name',
            'amendments.agendaItem:id,title,sort_order',
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
        if ($meeting->isArchived()) {
            return back()->with('error', 'This meeting is archived. Restore it before making changes.');
        }

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

        // Fan out the draft minutes email to every ExCo/Chair user with
        // a valid mailbox. Failures are caught + logged so the click
        // still succeeds — the operator can retry from the email log if
        // Mailgun is down. Notification is queued, so this is fast.
        $recipients = $this->circulationRecipients($request->user());
        $sentCount = 0;

        if ($recipients->isNotEmpty()) {
            try {
                Notification::send(
                    $recipients,
                    new MinutesCirculatedNotification($meeting, $request->user()),
                );
                $sentCount = $recipients->count();
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch minutes-circulated email', [
                    'meeting_id' => $meeting->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $flash = $sentCount > 0
            ? "Minutes marked as circulated. Draft emailed to {$sentCount} ExCo member".($sentCount === 1 ? '' : 's').'.'
            : 'Minutes marked as circulated. No ExCo members with a mailbox were found — send the draft manually.';

        return back()->with('success', $flash);
    }

    /**
     * ExCo/Chair users who should receive the "please review the draft
     * minutes" email. Excludes the circulator themselves (they already
     * know), users without an email, and unverified accounts.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function circulationRecipients(User $circulator)
    {
        return User::query()
            ->role(['exco', 'chair'])
            ->whereNotNull('email')
            ->whereNotNull('email_verified_at')
            ->whereKeyNot($circulator->id)
            ->get();
    }

    /**
     * Record that the circulated minutes were formally adopted at a
     * subsequent sitting. The adopting meeting must also exist in the
     * platform so we keep a two-way audit link.
     */
    public function markAdopted(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        if ($meeting->isArchived()) {
            return back()->with('error', 'This meeting is archived. Restore it before making changes.');
        }

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
