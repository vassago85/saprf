<?php

namespace App\Http\Controllers\Exco;

use App\Enums\ExcoActionStatus;
use App\Http\Controllers\Controller;
use App\Models\ExcoAction;
use App\Models\ExcoAgendaItem;
use App\Models\ExcoMeeting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ExCo action items — the "what's going on between meetings" register.
 *
 * Actions can be created three ways:
 *   - Standalone from the /exco/actions index
 *   - Attached to a meeting from the meeting show page (POST to
 *     meetings/{meeting}/actions)
 *   - Attached to a specific agenda item on that meeting
 *
 * All three land here; the redirect target is decided by which form
 * originated the request.
 */
class ExcoActionController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status', 'open');
        $status = in_array($status, ['open', 'done', 'cancelled', 'all'], true) ? $status : 'open';

        $query = ExcoAction::query()
            ->with(['assignee:id,name', 'creator:id,name', 'meeting:id,title', 'agendaItem:id,title'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'done' THEN 1 ELSE 2 END")
            ->orderBy('due_on');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return view('exco.actions.index', [
            'actions' => $query->paginate(30)->withQueryString(),
            'excoUsers' => $this->excoDirectory(),
            'currentStatus' => $status,
        ]);
    }

    /**
     * Standalone create from the actions index — no meeting / agenda
     * item context. Meeting-scoped creates come through storeForMeeting.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedForStandalone($request);

        ExcoAction::create([
            'title' => $data['title'],
            'details' => $data['details'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'due_on' => $data['due_on'] ?? null,
            'status' => ExcoActionStatus::Open,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('exco.actions.index')
            ->with('success', 'Action item added.');
    }

    /**
     * Meeting-scoped create — optionally tied to a specific agenda
     * item on that meeting.
     */
    public function storeForMeeting(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        $data = $this->validatedForMeeting($request);

        $agendaItemId = null;
        if (! empty($data['agenda_item_id'])) {
            $agendaItemId = ExcoAgendaItem::where('id', $data['agenda_item_id'])
                ->where('meeting_id', $meeting->id)
                ->value('id');
        }

        ExcoAction::create([
            'title' => $data['title'],
            'details' => $data['details'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'due_on' => $data['due_on'] ?? null,
            'status' => ExcoActionStatus::Open,
            'meeting_id' => $meeting->id,
            'agenda_item_id' => $agendaItemId,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', 'Action added.');
    }

    public function update(Request $request, ExcoAction $action): RedirectResponse
    {
        // Actions on a meeting whose minutes have already been adopted
        // are historical record — content is locked. Status toggles and
        // deletion are handled by separate endpoints and stay available.
        abort_unless($action->isEditable(), 403, 'This action item is locked because its minutes have been adopted.');

        $data = $this->validatedForStandalone($request);

        $action->update([
            'title' => $data['title'],
            'details' => $data['details'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'due_on' => $data['due_on'] ?? null,
        ]);

        return back()->with('success', 'Action updated.');
    }

    /**
     * Fast status toggle from the list: open -> done, or done -> open.
     * `completed_at` is stamped on the down-transition and cleared on
     * the up-transition so exports are still meaningful after a slip.
     */
    public function setStatus(Request $request, ExcoAction $action): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', array_column(ExcoActionStatus::cases(), 'value'))],
        ]);

        $target = ExcoActionStatus::from($data['status']);

        $action->update([
            'status' => $target,
            'completed_at' => $target === ExcoActionStatus::Done ? now() : null,
        ]);

        return back()->with('success', 'Action status updated.');
    }

    public function destroy(ExcoAction $action): RedirectResponse
    {
        $meetingId = $action->meeting_id;
        $action->delete();

        if ($meetingId) {
            return redirect()->route('exco.meetings.show', $meetingId)
                ->with('success', 'Action removed.');
        }

        return redirect()->route('exco.actions.index')
            ->with('success', 'Action removed.');
    }

    private function validatedForStandalone(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'details' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_on' => ['nullable', 'date'],
        ]);
    }

    private function validatedForMeeting(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'details' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_on' => ['nullable', 'date'],
            'agenda_item_id' => ['nullable', 'integer'],
        ]);
    }

    private function excoDirectory()
    {
        return User::query()
            ->role(['exco', 'chair', 'developer'])
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
