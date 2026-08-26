<x-layouts.app :title="'ExCo Actions'">
    <div class="max-w-5xl space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900">ExCo Actions</h1>
                <p class="mt-1 text-sm text-stone-500">All follow-up items across meetings. Filter by status; check off as complete.</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @foreach (['open' => 'Open', 'done' => 'Done', 'cancelled' => 'Cancelled', 'all' => 'All'] as $key => $label)
                <a href="{{ route('exco.actions.index', ['status' => $key]) }}"
                    class="rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $currentStatus === $key ? 'bg-emerald-600 text-white ring-emerald-600' : 'bg-white text-stone-600 ring-stone-200 hover:bg-stone-50' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">Action</th>
                        <th class="px-4 py-3 text-left font-semibold">Owner</th>
                        <th class="px-4 py-3 text-left font-semibold">Due</th>
                        <th class="px-4 py-3 text-left font-semibold">From</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Toggle</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($actions as $action)
                        <tr>
                            <td class="px-4 py-3 text-stone-900">
                                <p class="font-medium">{{ $action->title }}</p>
                                @if ($action->details)
                                    <p class="mt-0.5 whitespace-pre-wrap text-xs text-stone-500">{{ $action->details }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $action->assignee?->name ?? '—' }}</td>
                            <td class="px-4 py-3 {{ $action->isOverdue() ? 'font-semibold text-red-700' : 'text-stone-600' }}">
                                {{ $action->due_on?->format('d M Y') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-stone-500">
                                @if ($action->meeting)
                                    <a href="{{ route('exco.meetings.show', $action->meeting_id) }}" class="hover:text-emerald-700">{{ $action->meeting->title }}</a>
                                @else
                                    Ad-hoc
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $action->status->badgeClass() }}">
                                    {{ $action->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    @if ($action->isOpen())
                                        <form method="POST" action="{{ route('exco.actions.set-status', $action) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="done">
                                            <button type="submit" class="rounded-lg bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">Done</button>
                                        </form>
                                        <form method="POST" action="{{ route('exco.actions.set-status', $action) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="cancelled">
                                            <button type="submit" class="rounded-lg bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-200">Cancel</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('exco.actions.set-status', $action) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="open">
                                            <button type="submit" class="rounded-lg bg-stone-100 px-2 py-1 text-xs font-semibold text-stone-700 hover:bg-stone-200">Reopen</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('exco.actions.destroy', $action) }}"
                                        onsubmit="return confirm('Delete this action?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-lg bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-100">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-stone-400">
                                No {{ $currentStatus === 'all' ? '' : $currentStatus }} action items.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $actions->links() }}</div>

        {{-- Add ad-hoc action --}}
        <form method="POST" action="{{ route('exco.actions.store') }}"
            class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-5 space-y-3">
            @csrf
            <h2 class="font-heading text-base font-semibold text-emerald-900">Add ad-hoc action</h2>
            <p class="text-xs text-stone-600">Something to track between meetings that does not belong to any single sitting.</p>
            <div>
                <label class="block text-xs font-semibold uppercase text-stone-500">Title</label>
                <input type="text" name="title" required maxlength="200"
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
            </div>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500">Owner</label>
                    <select name="assigned_to"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                        <option value="">— unassigned —</option>
                        @foreach ($excoUsers as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500">Due date</label>
                    <input type="date" name="due_on"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-stone-500">Details (optional)</label>
                <textarea name="details" rows="2" maxlength="5000"
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div>
                <button type="submit"
                    class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                    + Add action
                </button>
            </div>
        </form>
    </div>
</x-layouts.app>
