<?php

namespace App\Http\Controllers\Exco;

use App\Enums\ExcoAgendaItemVisibility;
use App\Http\Controllers\Controller;
use App\Models\DisciplinaryCase;
use App\Models\ExcoAgendaItem;
use App\Models\ExcoMeeting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Agenda-item write-side: create, edit, reorder, delete. Read happens
 * inside the ExcoMeetingController::show view. Confidential items and
 * `disciplinary_case_id` links are treated as ordinary fields — the
 * confidentiality is a display flag, not a further access gate (the
 * whole ExCo workspace is already gated).
 */
class ExcoAgendaItemController extends Controller
{
    public function store(Request $request, ExcoMeeting $meeting): RedirectResponse
    {
        $data = $this->validated($request);

        if ($meeting->isClosed()) {
            return back()->with('error', 'Cannot add agenda items to a closed meeting.');
        }

        $nextOrder = ((int) $meeting->agendaItems()->max('sort_order')) + 1;

        ExcoAgendaItem::create([
            'meeting_id' => $meeting->id,
            'sort_order' => $nextOrder,
            'title' => $data['title'],
            'briefing' => $data['briefing'] ?? null,
            'minutes' => $data['minutes'] ?? null,
            'visibility' => $data['visibility'] ?? ExcoAgendaItemVisibility::Ordinary->value,
            'disciplinary_case_id' => $this->resolveCaseId($data['disciplinary_case_id'] ?? null),
        ]);

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', 'Agenda item added.');
    }

    public function update(Request $request, ExcoMeeting $meeting, ExcoAgendaItem $agendaItem): RedirectResponse
    {
        $this->ensureBelongsTo($meeting, $agendaItem);

        if ($meeting->isClosed()) {
            return back()->with('error', 'Cannot edit agenda items on a closed meeting.');
        }

        $data = $this->validated($request);

        $agendaItem->update([
            'title' => $data['title'],
            'briefing' => $data['briefing'] ?? null,
            'minutes' => $data['minutes'] ?? null,
            'visibility' => $data['visibility'] ?? ExcoAgendaItemVisibility::Ordinary->value,
            'disciplinary_case_id' => $this->resolveCaseId($data['disciplinary_case_id'] ?? null),
        ]);

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', 'Agenda item saved.');
    }

    public function destroy(ExcoMeeting $meeting, ExcoAgendaItem $agendaItem): RedirectResponse
    {
        $this->ensureBelongsTo($meeting, $agendaItem);

        if ($meeting->isClosed()) {
            return back()->with('error', 'Cannot remove agenda items from a closed meeting.');
        }

        $agendaItem->delete();

        return redirect()->route('exco.meetings.show', $meeting)
            ->with('success', 'Agenda item removed.');
    }

    /**
     * Shuffle one item up or down. Kept as +/- 1 nudges rather than a
     * drag-and-drop payload — simpler HTML, works without JS, and the
     * agendas are small enough that it does not matter.
     */
    public function move(Request $request, ExcoMeeting $meeting, ExcoAgendaItem $agendaItem): RedirectResponse
    {
        $this->ensureBelongsTo($meeting, $agendaItem);

        if ($meeting->isClosed()) {
            return back()->with('error', 'Cannot reorder items on a closed meeting.');
        }

        $direction = $request->validate([
            'direction' => ['required', 'string', 'in:up,down'],
        ])['direction'];

        $items = $meeting->agendaItems()->get()->values();
        $currentIndex = $items->search(fn ($i) => $i->id === $agendaItem->id);

        if ($currentIndex === false) {
            return back();
        }

        $swapIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
        if ($swapIndex < 0 || $swapIndex >= $items->count()) {
            return back();
        }

        $neighbour = $items[$swapIndex];

        // Reassign sort_order values based on the new position of every
        // item — this avoids duplicate `sort_order` values when the
        // original set had gaps or ties.
        $reordered = $items->all();
        [$reordered[$currentIndex], $reordered[$swapIndex]] = [$reordered[$swapIndex], $reordered[$currentIndex]];

        foreach ($reordered as $idx => $item) {
            /** @var ExcoAgendaItem $item */
            $item->update(['sort_order' => $idx + 1]);
        }

        // Silence unused-var warning without eating the variable — we
        // referenced neighbour for clarity in the block above.
        unset($neighbour);

        return redirect()->route('exco.meetings.show', $meeting);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'briefing' => ['nullable', 'string', 'max:10000'],
            'minutes' => ['nullable', 'string', 'max:10000'],
            'visibility' => ['nullable', 'string', 'in:' . implode(',', array_column(ExcoAgendaItemVisibility::cases(), 'value'))],
            'disciplinary_case_id' => ['nullable', 'integer'],
        ]);
    }

    /**
     * Only accept a `disciplinary_case_id` that actually exists — a
     * hand-crafted request cannot silently link an agenda item to a
     * bogus id.
     */
    private function resolveCaseId(?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return DisciplinaryCase::whereKey($id)->value('id');
    }

    private function ensureBelongsTo(ExcoMeeting $meeting, ExcoAgendaItem $agendaItem): void
    {
        abort_unless($agendaItem->meeting_id === $meeting->id, 404);
    }
}
