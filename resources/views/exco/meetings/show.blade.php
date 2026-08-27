<x-layouts.app :title="$meeting->title">
    <div class="max-w-5xl space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <a href="{{ route('exco.meetings.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← All meetings</a>
                <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900">{{ $meeting->title }}</h1>
                <p class="mt-1 text-sm text-stone-500">
                    {{ $meeting->type->label() }} · {{ $meeting->scheduled_at->format('D d M Y H:i') }}
                    @if ($meeting->location) · {{ $meeting->location }} @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $meeting->status->badgeClass() }}">
                    {{ $meeting->status->label() }}
                </span>
                @if ($meeting->minutesAreCirculated())
                    <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                        Circulated
                    </span>
                @endif
                @if ($meeting->minutesAreAdopted())
                    <span class="inline-flex rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-800">
                        Adopted
                    </span>
                @endif
                @unless ($meeting->isDraft())
                    <a href="{{ route('exco.meetings.minutes.print', $meeting) }}" target="_blank"
                        class="rounded-lg bg-white ring-1 ring-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                        Print / PDF
                    </a>
                @endunless
                @unless($meeting->isClosed())
                    <a href="{{ route('exco.meetings.edit', $meeting) }}"
                        class="rounded-lg bg-white ring-1 ring-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                        Edit details
                    </a>
                @endunless
            </div>
        </div>

        {{-- Status transitions --}}
        <div class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-center gap-3">
                <p class="text-sm font-medium text-stone-700">Meeting progress:</p>
                @if ($meeting->isDraft())
                    <form method="POST" action="{{ route('exco.meetings.transition', $meeting) }}" class="inline">
                        @csrf
                        <input type="hidden" name="status" value="held">
                        <button type="submit" class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">
                            Start meeting →
                        </button>
                    </form>
                    <p class="text-xs text-stone-500">You are still building the agenda.</p>
                @elseif ($meeting->status->value === 'held')
                    <form method="POST" action="{{ route('exco.meetings.transition', $meeting) }}" class="inline"
                        onsubmit="return confirm('Close the meeting? You will no longer be able to add or edit agenda items and minutes.');">
                        @csrf
                        <input type="hidden" name="status" value="closed">
                        <button type="submit" class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                            Close meeting
                        </button>
                    </form>
                    <p class="text-xs text-stone-500">Minutes are being captured — close when finished.</p>
                @else
                    <p class="text-xs text-stone-500">This meeting is closed. The record is read-only.</p>
                @endif
            </div>
        </div>

        {{-- Minutes circulation + adoption. Only surfaces once the sitting is
             closed — you can't circulate minutes of a meeting that is still
             in progress. Two-step: circulated → adopted. --}}
        @if ($meeting->isClosed())
            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-heading text-base font-semibold text-stone-900">Minutes lifecycle</h2>
                        <p class="mt-0.5 text-xs text-stone-500">Track when the drafted minutes were sent out for review and when they were formally adopted at a subsequent sitting.</p>
                    </div>
                    <a href="{{ route('exco.meetings.minutes.print', $meeting) }}" target="_blank"
                        class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                        Download minutes (PDF)
                    </a>
                </div>

                <ol class="mt-4 space-y-3">
                    {{-- Step 1: Circulated --}}
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold
                            {{ $meeting->minutesAreCirculated() ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-500' }}">
                            {{ $meeting->minutesAreCirculated() ? '✓' : '1' }}
                        </span>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-stone-900">Circulated to ExCo</p>
                            @if ($meeting->minutesAreCirculated())
                                <p class="mt-0.5 text-xs text-stone-500">
                                    {{ $meeting->minutes_circulated_at->format('D d M Y H:i') }}
                                    @if ($meeting->minutesCirculator) by {{ $meeting->minutesCirculator->name }} @endif
                                </p>
                            @else
                                <p class="mt-0.5 text-xs text-stone-500">Download the PDF above, email it to ExCo, then click below to record circulation.</p>
                                <form method="POST" action="{{ route('exco.meetings.mark-circulated', $meeting) }}" class="mt-2">
                                    @csrf
                                    <button type="submit" class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">
                                        Mark as circulated
                                    </button>
                                </form>
                            @endif
                        </div>
                    </li>

                    {{-- Step 2: Adopted --}}
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold
                            {{ $meeting->minutesAreAdopted() ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-500' }}">
                            {{ $meeting->minutesAreAdopted() ? '✓' : '2' }}
                        </span>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-stone-900">Adopted at a subsequent sitting</p>
                            @if ($meeting->minutesAreAdopted())
                                <p class="mt-0.5 text-xs text-stone-500">
                                    {{ $meeting->minutes_adopted_at->format('D d M Y') }}
                                    @if ($meeting->adoptedAtMeeting)
                                        at
                                        <a href="{{ route('exco.meetings.show', $meeting->adoptedAtMeeting) }}" class="font-semibold text-emerald-700 hover:text-emerald-800">
                                            {{ $meeting->adoptedAtMeeting->title }}
                                        </a>
                                    @endif
                                </p>
                            @elseif ($meeting->minutesAreCirculated())
                                <p class="mt-0.5 text-xs text-stone-500">Once ExCo has adopted these minutes (usually as item 1 of the next sitting), record it here.</p>
                                @if ($adoptionCandidates->isNotEmpty())
                                    <form method="POST" action="{{ route('exco.meetings.mark-adopted', $meeting) }}" class="mt-2 flex flex-wrap items-center gap-2">
                                        @csrf
                                        <label for="adopted_at_meeting_id" class="text-xs text-stone-500">Adopted at:</label>
                                        <select name="adopted_at_meeting_id" id="adopted_at_meeting_id" required
                                            class="rounded-lg border border-stone-300 px-2 py-1 text-xs">
                                            <option value="">— pick a meeting —</option>
                                            @foreach ($adoptionCandidates as $candidate)
                                                <option value="{{ $candidate->id }}">
                                                    {{ $candidate->title }} ({{ $candidate->scheduled_at->format('d M Y') }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                                            Mark as adopted
                                        </button>
                                    </form>
                                @else
                                    <p class="mt-2 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-500">
                                        Create the next sitting first — its title will appear here as the adopting meeting.
                                    </p>
                                @endif
                            @else
                                <p class="mt-0.5 text-xs text-stone-400">Circulate the minutes first.</p>
                            @endif
                        </div>
                    </li>
                </ol>
            </div>
        @endif

        @if ($meeting->attendance_notes)
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-base font-semibold text-stone-900">Attendance / notes</h2>
                <p class="mt-2 whitespace-pre-wrap text-sm text-stone-700">{{ $meeting->attendance_notes }}</p>
            </div>
        @endif

        {{-- Agenda + minutes --}}
        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Agenda &amp; minutes</h2>
                    <p class="text-xs text-stone-500">Add each agenda line; capture the minutes when the item is discussed.</p>
                </div>
            </div>

            <div class="mt-4 space-y-4">
                @forelse ($meeting->agendaItems as $index => $item)
                    <details class="rounded-lg border {{ $item->isConfidential() ? 'border-red-200 bg-red-50/40' : 'border-stone-200 bg-stone-50/40' }}" @if(request('open') == $item->id) open @endif>
                        <summary class="flex cursor-pointer items-center justify-between gap-3 rounded-lg px-4 py-3 text-sm">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex size-6 items-center justify-center rounded-full bg-stone-200 text-xs font-semibold text-stone-700">{{ $index + 1 }}</span>
                                <span class="font-semibold text-stone-900">{{ $item->title }}</span>
                                @if ($item->isConfidential())
                                    <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-red-800">
                                        Confidential
                                    </span>
                                @endif
                                @if ($item->disciplinaryCase)
                                    <a href="{{ route('exco.disciplinary.show', $item->disciplinaryCase) }}"
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800 hover:bg-amber-200">
                                        {{ $item->disciplinaryCase->reference }}
                                    </a>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                @unless ($meeting->isClosed())
                                    <form method="POST" action="{{ route('exco.meetings.agenda.move', [$meeting, $item]) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" title="Move up"
                                            class="rounded p-1 text-stone-400 hover:bg-stone-200 hover:text-stone-700">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5"/></svg>
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('exco.meetings.agenda.move', [$meeting, $item]) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" title="Move down"
                                            class="rounded p-1 text-stone-400 hover:bg-stone-200 hover:text-stone-700">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                                        </button>
                                    </form>
                                @endunless
                            </div>
                        </summary>

                        <div class="border-t {{ $item->isConfidential() ? 'border-red-200' : 'border-stone-200' }} p-4">
                            @if ($meeting->isClosed())
                                <div class="space-y-4 text-sm">
                                    @if ($item->briefing)
                                        <div>
                                            <p class="text-xs font-semibold uppercase text-stone-400">Briefing</p>
                                            <p class="mt-1 whitespace-pre-wrap text-stone-700">{{ $item->briefing }}</p>
                                        </div>
                                    @endif
                                    @if ($item->minutes)
                                        <div>
                                            <p class="text-xs font-semibold uppercase text-stone-400">Minutes</p>
                                            <p class="mt-1 whitespace-pre-wrap text-stone-800">{{ $item->minutes }}</p>
                                        </div>
                                    @else
                                        <p class="text-xs italic text-stone-400">No minutes captured for this item.</p>
                                    @endif
                                </div>
                            @else
                                <form method="POST" action="{{ route('exco.meetings.agenda.update', [$meeting, $item]) }}" class="space-y-3">
                                    @csrf
                                    @method('PUT')
                                    <div>
                                        <label class="block text-xs font-semibold uppercase text-stone-500">Title</label>
                                        <input type="text" name="title" required maxlength="200"
                                            value="{{ old('title', $item->title) }}"
                                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                                    </div>
                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                        <div>
                                            <label class="block text-xs font-semibold uppercase text-stone-500">Briefing (pre-meeting)</label>
                                            <textarea name="briefing" rows="4" maxlength="10000"
                                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('briefing', $item->briefing) }}</textarea>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold uppercase text-stone-500">Minutes (during / after)</label>
                                            <textarea name="minutes" rows="4" maxlength="10000"
                                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('minutes', $item->minutes) }}</textarea>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                        <div>
                                            <label class="block text-xs font-semibold uppercase text-stone-500">Visibility</label>
                                            <select name="visibility"
                                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                                                @foreach ($visibilities as $v)
                                                    <option value="{{ $v->value }}" @selected($item->visibility === $v)>{{ $v->label() }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold uppercase text-stone-500">Linked disciplinary case</label>
                                            <select name="disciplinary_case_id"
                                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                                                <option value="">— none —</option>
                                                @foreach ($cases as $c)
                                                    <option value="{{ $c->id }}" @selected($item->disciplinary_case_id === $c->id)>
                                                        {{ $c->reference }} — {{ $c->title }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div class="pt-1">
                                        <button type="submit"
                                            class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                                            Save item
                                        </button>
                                    </div>
                                </form>
                                <form method="POST" action="{{ route('exco.meetings.agenda.destroy', [$meeting, $item]) }}"
                                    onsubmit="return confirm('Remove this agenda item?');" class="mt-3">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                        class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                                        Remove item
                                    </button>
                                </form>
                            @endif
                        </div>
                    </details>
                @empty
                    <p class="rounded-lg border border-dashed border-stone-300 px-4 py-6 text-center text-sm text-stone-400">
                        No agenda items yet. Add the first one below.
                    </p>
                @endforelse
            </div>

            @unless ($meeting->isClosed())
                <details class="mt-6 rounded-lg border border-stone-200 bg-stone-50/60 p-4">
                    <summary class="cursor-pointer text-sm font-semibold text-stone-800">
                        Bulk import agenda items from JSON
                    </summary>
                    <p class="mt-2 text-xs text-stone-500">
                        Paste an <code>agenda_items</code> array to append multiple items at once. Use the
                        <a href="{{ route('exco.prompts') }}" class="font-semibold text-emerald-700 hover:text-emerald-800">notice → JSON AI prompt</a>
                        to convert a written agenda into this shape.
                    </p>
                    <form method="POST" action="{{ route('exco.meetings.agenda.import', $meeting) }}" class="mt-3 space-y-2">
                        @csrf
                        <textarea name="payload" rows="8" required
                            class="block w-full rounded-lg border border-stone-300 px-3 py-2 font-mono text-xs"
                            placeholder='{ "agenda_items": [ { "title": "Welcome", "briefing": "..." } ] }'></textarea>
                        <div class="flex justify-end">
                            <button type="submit"
                                class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800">
                                Import items
                            </button>
                        </div>
                    </form>
                </details>

                <form method="POST" action="{{ route('exco.meetings.agenda.store', $meeting) }}"
                    class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/40 p-4 space-y-3">
                    @csrf
                    <h3 class="text-sm font-semibold text-emerald-900">Add agenda item</h3>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-stone-500">Title</label>
                        <input type="text" name="title" required maxlength="200" placeholder="e.g. Ratify 2026 budget"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs font-semibold uppercase text-stone-500">Briefing (optional)</label>
                            <textarea name="briefing" rows="2" maxlength="10000"
                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"></textarea>
                        </div>
                        <div class="space-y-2">
                            <div>
                                <label class="block text-xs font-semibold uppercase text-stone-500">Visibility</label>
                                <select name="visibility"
                                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                                    @foreach ($visibilities as $v)
                                        <option value="{{ $v->value }}">{{ $v->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-stone-500">Linked disciplinary case (optional)</label>
                                <select name="disciplinary_case_id"
                                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                                    <option value="">— none —</option>
                                    @foreach ($cases as $c)
                                        <option value="{{ $c->id }}">{{ $c->reference }} — {{ $c->title }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div>
                        <button type="submit"
                            class="rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">
                            + Add item
                        </button>
                    </div>
                </form>
            @endunless
        </div>

        {{-- Action items on this meeting --}}
        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">Action items from this meeting</h2>
                    <p class="text-xs text-stone-500">Who is doing what by when. Manage the full backlog under ExCo → Actions.</p>
                </div>
            </div>

            <div class="mt-4 divide-y divide-stone-100">
                @forelse ($meeting->actions as $action)
                    <div class="flex items-start justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="font-medium text-stone-900">{{ $action->title }}</p>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ $action->status->badgeClass() }}">
                                    {{ $action->status->label() }}
                                </span>
                                @if ($action->isOverdue())
                                    <span class="inline-flex rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-semibold text-white">OVERDUE</span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-xs text-stone-500">
                                @if ($action->assignee) Owner: {{ $action->assignee->name }} · @endif
                                @if ($action->due_on) Due {{ $action->due_on->format('d M Y') }} · @endif
                                @if ($action->agendaItem) Item: {{ $action->agendaItem->title }} @endif
                            </p>
                            @if ($action->details)
                                <p class="mt-1 whitespace-pre-wrap text-xs text-stone-600">{{ $action->details }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-1">
                            @if ($action->isOpen())
                                <form method="POST" action="{{ route('exco.actions.set-status', $action) }}">
                                    @csrf
                                    <input type="hidden" name="status" value="done">
                                    <button type="submit" class="rounded-lg bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">
                                        Mark done
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('exco.actions.set-status', $action) }}">
                                    @csrf
                                    <input type="hidden" name="status" value="open">
                                    <button type="submit" class="rounded-lg bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-200">
                                        Reopen
                                    </button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('exco.actions.destroy', $action) }}"
                                onsubmit="return confirm('Delete this action?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-lg bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-100">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="py-3 text-sm text-stone-400">No actions on this meeting yet.</p>
                @endforelse
            </div>

            @unless ($meeting->isClosed())
                <form method="POST" action="{{ route('exco.meetings.actions.store', $meeting) }}"
                    class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50/40 p-4 space-y-3">
                    @csrf
                    <h3 class="text-sm font-semibold text-emerald-900">Add action item</h3>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-stone-500">Title</label>
                        <input type="text" name="title" required maxlength="200"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="block text-xs font-semibold uppercase text-stone-500">Owner</label>
                            <select name="assigned_to"
                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                                <option value="">— unassigned —</option>
                                @foreach ($excoUsers as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-stone-500">Due date</label>
                            <input type="date" name="due_on"
                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-stone-500">Link to agenda item (optional)</label>
                            <select name="agenda_item_id"
                                class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                                <option value="">— none —</option>
                                @foreach ($meeting->agendaItems as $item)
                                    <option value="{{ $item->id }}">{{ $item->title }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-stone-500">Details (optional)</label>
                        <textarea name="details" rows="2" maxlength="5000"
                            class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"></textarea>
                    </div>
                    <div>
                        <button type="submit"
                            class="rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">
                            + Add action
                        </button>
                    </div>
                </form>
            @endunless
        </div>

        {{-- Danger zone. Draft + held meetings can be removed for cleanup;
             closed meetings are historical and the destroy() controller
             refuses them regardless of what this template offers. --}}
        @unless ($meeting->isClosed())
            <div class="rounded-xl border border-red-200 bg-red-50/40 p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-heading text-sm font-semibold text-red-900">Delete this meeting</h2>
                        <p class="mt-0.5 text-xs text-red-800/80">
                            Removes the meeting, all its agenda items and all its follow-up actions.
                            Only available while the meeting is a draft or in progress — closed meetings
                            are locked so the record can't be tampered with.
                        </p>
                    </div>
                    <form method="POST" action="{{ route('exco.meetings.destroy', $meeting) }}"
                        onsubmit="return confirm('Delete this meeting? Agenda items, minutes and actions on it will be removed. This cannot be undone.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">
                            Delete meeting
                        </button>
                    </form>
                </div>
            </div>
        @endunless
    </div>
</x-layouts.app>
