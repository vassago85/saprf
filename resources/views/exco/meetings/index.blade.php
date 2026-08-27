<x-layouts.app :title="'ExCo Meetings'">
    <div class="max-w-5xl space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900">ExCo Meetings</h1>
                <p class="mt-1 text-sm text-stone-500">Agenda, minutes and follow-up actions for the National Executive Committee.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('exco.meetings.import.form') }}"
                    class="inline-flex items-center gap-2 rounded-xl bg-white ring-1 ring-stone-200 px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                    Import from JSON
                </a>
                <a href="{{ route('exco.meetings.create') }}"
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    New meeting
                </a>
            </div>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
            <p><span class="font-semibold">Confidential.</span> Everything in this section is visible to ExCo, Chair and developers only. Owner, admin and members do not see these pages or any linked disciplinary case files.</p>
        </div>

        {{-- View switcher. "Archived" is a soft-delete escape hatch — closed
             meetings that were duplicates, tests, or otherwise shouldn't be
             in the active record. --}}
        <div class="flex items-center gap-1 border-b border-stone-200">
            <a href="{{ route('exco.meetings.index') }}"
                class="border-b-2 px-4 py-2 text-sm font-semibold transition
                    {{ ($view ?? 'active') === 'active' ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-stone-500 hover:text-stone-700' }}">
                Active
            </a>
            <a href="{{ route('exco.meetings.index', ['archived' => 1]) }}"
                class="border-b-2 px-4 py-2 text-sm font-semibold transition
                    {{ ($view ?? 'active') === 'archived' ? 'border-stone-800 text-stone-900' : 'border-transparent text-stone-500 hover:text-stone-700' }}">
                Archived
                @if (($view ?? 'active') === 'active' && ($archivedCount ?? 0) > 0)
                    <span class="ml-1 rounded-full bg-stone-200 px-2 py-0.5 text-[10px] font-semibold text-stone-700">{{ $archivedCount }}</span>
                @endif
            </a>
        </div>

        @if (($view ?? 'active') === 'archived')
            {{-- Archived view: single table of soft-hidden meetings with the
                 who / when / why context inline. Restore is done from the
                 meeting's show page. --}}
            <div>
                <h2 class="font-heading text-lg font-semibold text-stone-800">Archived meetings</h2>
                <p class="mt-0.5 text-xs text-stone-500">Soft-hidden closed meetings. Nothing is deleted — open any row and click "Restore from archive" to bring it back.</p>

                <div class="mt-3 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Title</th>
                                <th class="px-4 py-3 text-left font-semibold">Sitting date</th>
                                <th class="px-4 py-3 text-left font-semibold">Archived</th>
                                <th class="px-4 py-3 text-left font-semibold">Archived by</th>
                                <th class="px-4 py-3 text-left font-semibold">Reason</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($archived as $meeting)
                                <tr class="hover:bg-stone-50">
                                    <td class="px-4 py-3 font-medium text-stone-900">
                                        <a href="{{ route('exco.meetings.show', $meeting) }}" class="hover:text-emerald-700">
                                            {{ $meeting->title }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-stone-600">{{ $meeting->scheduled_at->format('d M Y H:i') }}</td>
                                    <td class="px-4 py-3 text-stone-600">{{ $meeting->archived_at->format('d M Y H:i') }}</td>
                                    <td class="px-4 py-3 text-stone-500">{{ $meeting->archiver?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-stone-500 italic">{{ $meeting->archive_reason ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-stone-400">Nothing archived.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">{{ $archived->links() }}</div>
            </div>
        @else
            <div>
                <h2 class="font-heading text-lg font-semibold text-stone-800">Upcoming &amp; in progress</h2>
                <div class="mt-3 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Title</th>
                                <th class="px-4 py-3 text-left font-semibold">When</th>
                                <th class="px-4 py-3 text-left font-semibold">Type</th>
                                <th class="px-4 py-3 text-left font-semibold">Status</th>
                                <th class="px-4 py-3 text-left font-semibold">Created by</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($upcoming as $meeting)
                                <tr class="hover:bg-stone-50">
                                    <td class="px-4 py-3 font-medium text-stone-900">
                                        <a href="{{ route('exco.meetings.show', $meeting) }}" class="hover:text-emerald-700">
                                            {{ $meeting->title }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-stone-600">{{ $meeting->scheduled_at->format('D d M Y H:i') }}</td>
                                    <td class="px-4 py-3 text-stone-600">{{ $meeting->type->label() }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $meeting->status->badgeClass() }}">
                                            {{ $meeting->status->label() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-stone-500">{{ $meeting->creator?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-stone-400">
                                        No open meetings. Create one for tonight's sitting.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <h2 class="font-heading text-lg font-semibold text-stone-800">Past (closed)</h2>
                <div class="mt-3 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Title</th>
                                <th class="px-4 py-3 text-left font-semibold">When</th>
                                <th class="px-4 py-3 text-left font-semibold">Type</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($past as $meeting)
                                <tr class="hover:bg-stone-50">
                                    <td class="px-4 py-3 font-medium text-stone-900">
                                        <a href="{{ route('exco.meetings.show', $meeting) }}" class="hover:text-emerald-700">
                                            {{ $meeting->title }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-stone-600">{{ $meeting->scheduled_at->format('d M Y H:i') }}</td>
                                    <td class="px-4 py-3 text-stone-600">{{ $meeting->type->label() }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 py-8 text-center text-sm text-stone-400">No closed meetings yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">{{ $past->links() }}</div>
            </div>
        @endif
    </div>
</x-layouts.app>
