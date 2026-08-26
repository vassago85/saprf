<x-layouts.app :title="$case->reference . ' — ' . $case->title">
    <div class="max-w-4xl space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <a href="{{ route('exco.disciplinary.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← All cases</a>
                <div class="mt-1 flex items-center gap-3">
                    <p class="font-mono text-sm font-semibold text-stone-500">{{ $case->reference }}</p>
                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $case->status->badgeClass() }}">
                        {{ $case->status->label() }}
                    </span>
                </div>
                <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900">{{ $case->title }}</h1>
                <p class="mt-1 text-sm text-stone-500">
                    Subject: <strong>{{ $case->subjectLabel() }}</strong>
                    @if ($case->subject)
                        <span class="text-xs text-stone-400">(SAPRF member)</span>
                    @else
                        <span class="text-xs text-stone-400">(external)</span>
                    @endif
                    · Opened {{ $case->opened_at?->format('d M Y') ?? '—' }}
                    · By {{ $case->creator?->name ?? '—' }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('exco.disciplinary.edit', $case) }}"
                    class="rounded-lg bg-white ring-1 ring-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                    Edit
                </a>
            </div>
        </div>

        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs text-red-900">
            <p><span class="font-semibold">POPIA:</span> This record contains personal information. Access is limited to ExCo, Chair and developers. Every attachment download is audit-logged.</p>
        </div>

        @if ($case->summary)
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-base font-semibold text-stone-900">Summary</h2>
                <p class="mt-2 whitespace-pre-wrap text-sm text-stone-800">{{ $case->summary }}</p>
            </div>
        @endif

        {{-- Notes timeline --}}
        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-base font-semibold text-stone-900">Notes</h2>

            <form method="POST" action="{{ route('exco.disciplinary.notes.store', $case) }}" class="mt-3 space-y-3">
                @csrf
                <textarea name="body" required rows="3" maxlength="10000" placeholder="Add a note — meeting outcome, phone call, correspondence…"
                    class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"></textarea>
                <button type="submit"
                    class="rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">
                    Add note
                </button>
            </form>

            <ol class="mt-6 space-y-4">
                @forelse ($case->notes as $note)
                    <li class="rounded-lg border border-stone-200 bg-stone-50/60 p-3">
                        <div class="flex items-center justify-between">
                            <div class="text-xs text-stone-500">
                                <strong class="text-stone-700">{{ $note->creator?->name ?? '—' }}</strong>
                                · {{ $note->created_at->format('d M Y H:i') }}
                            </div>
                            @if (auth()->id() === $note->created_by || auth()->user()->hasRole('developer'))
                                <form method="POST" action="{{ route('exco.disciplinary.notes.destroy', [$case, $note]) }}"
                                    onsubmit="return confirm('Remove your note?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-medium text-red-700 hover:text-red-800">Remove</button>
                                </form>
                            @endif
                        </div>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-stone-800">{{ $note->body }}</p>
                    </li>
                @empty
                    <li class="text-sm text-stone-400 italic">No notes yet.</li>
                @endforelse
            </ol>
        </div>

        {{-- Attachments --}}
        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-base font-semibold text-stone-900">Evidence &amp; attachments</h2>

            <form method="POST" action="{{ route('exco.disciplinary.attachments.store', $case) }}"
                enctype="multipart/form-data" class="mt-3 space-y-2">
                @csrf
                <input type="file" name="file" required
                    accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.doc,.xlsx,.xls,.txt"
                    class="block w-full text-sm text-stone-700 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-emerald-700 hover:file:bg-emerald-100">
                <button type="submit"
                    class="rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">
                    Upload
                </button>
                <p class="text-xs text-stone-400">Up to 20 MB. Stored on the private disciplinary disk — never accessible by URL guess.</p>
            </form>

            @if ($case->attachments->isNotEmpty())
                <ul class="mt-4 divide-y divide-stone-100">
                    @foreach ($case->attachments as $attachment)
                        <li class="flex items-center justify-between py-2 gap-3">
                            <div class="flex items-center gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 text-stone-400"><path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/></svg>
                                <div class="text-sm">
                                    <p class="font-medium text-stone-900">{{ $attachment->filename }}</p>
                                    <p class="text-xs text-stone-400">
                                        {{ $attachment->mime }} · {{ number_format($attachment->size / 1024, 1) }} KB
                                        · uploaded by {{ $attachment->uploader?->name ?? '—' }}
                                        · {{ $attachment->created_at->format('d M Y') }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('exco.disciplinary.attachments.download', [$case, $attachment]) }}"
                                    class="rounded-lg bg-white ring-1 ring-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                                    Download
                                </a>
                                <form method="POST" action="{{ route('exco.disciplinary.attachments.destroy', [$case, $attachment]) }}"
                                    onsubmit="return confirm('Remove this attachment? The file will be deleted from the disciplinary disk.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                                        Remove
                                    </button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-stone-400 italic">No attachments yet.</p>
            @endif
        </div>

        {{-- Related actions --}}
        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-base font-semibold text-stone-900">Follow-up actions on this case</h2>
            @if ($case->actions->isEmpty())
                <p class="mt-2 text-sm text-stone-400 italic">No actions tagged to this case yet — actions are added from a meeting page or the Actions index.</p>
            @else
                <ul class="mt-3 divide-y divide-stone-100">
                    @foreach ($case->actions as $action)
                        <li class="py-2 text-sm">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ $action->status->badgeClass() }}">
                                    {{ $action->status->label() }}
                                </span>
                                <p class="font-medium text-stone-900">{{ $action->title }}</p>
                            </div>
                            <p class="mt-0.5 text-xs text-stone-500">
                                @if ($action->assignee) Owner: {{ $action->assignee->name }} @endif
                                @if ($action->due_on) · Due {{ $action->due_on->format('d M Y') }} @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Meetings that referenced this case --}}
        @if ($case->agendaItems->isNotEmpty())
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-base font-semibold text-stone-900">Tabled at</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($case->agendaItems as $item)
                        <li>
                            <a href="{{ route('exco.meetings.show', $item->meeting_id) }}?open={{ $item->id }}"
                                class="font-medium text-emerald-700 hover:text-emerald-800">
                                {{ $item->meeting?->title ?? 'Meeting' }}
                            </a>
                            <span class="ml-2 text-xs text-stone-500">
                                {{ $item->meeting?->scheduled_at?->format('d M Y') ?? '' }} · {{ $item->title }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Delete (only when empty) --}}
        @if ($case->notes->isEmpty() && $case->attachments->isEmpty())
            <div class="rounded-xl border border-red-200 bg-red-50/60 p-4">
                <p class="text-xs text-red-900">
                    This case has no notes or attachments — you can still delete it. Once notes or attachments exist, close the case instead.
                </p>
                <form method="POST" action="{{ route('exco.disciplinary.destroy', $case) }}"
                    onsubmit="return confirm('Delete this case? This cannot be undone.');" class="mt-2">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                        class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">
                        Delete empty case
                    </button>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
