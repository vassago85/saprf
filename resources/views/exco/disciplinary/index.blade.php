<x-layouts.app :title="'Disciplinary Cases'">
    <div class="max-w-5xl space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Disciplinary Cases</h1>
                <p class="mt-1 text-sm text-stone-500">Confidential ExCo case register. Notes, evidence and follow-up in one place.</p>
            </div>
            <a href="{{ route('exco.disciplinary.create') }}"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Open case
            </a>
        </div>

        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs text-red-900">
            <p><span class="font-semibold">Handle with care.</span> These records are POPIA-sensitive. Do not screenshot, forward, or copy summaries into unrestricted channels. Attachments are downloaded through this page only.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('exco.disciplinary.index', ['status' => 'all']) }}"
                class="rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $currentStatus === 'all' ? 'bg-emerald-600 text-white ring-emerald-600' : 'bg-white text-stone-600 ring-stone-200 hover:bg-stone-50' }}">
                All
            </a>
            @foreach ($statuses as $status)
                <a href="{{ route('exco.disciplinary.index', ['status' => $status->value]) }}"
                    class="rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $currentStatus === $status->value ? 'bg-emerald-600 text-white ring-emerald-600' : 'bg-white text-stone-600 ring-stone-200 hover:bg-stone-50' }}">
                    {{ $status->label() }}
                </a>
            @endforeach
        </div>

        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">Ref</th>
                        <th class="px-4 py-3 text-left font-semibold">Subject</th>
                        <th class="px-4 py-3 text-left font-semibold">Title</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                        <th class="px-4 py-3 text-left font-semibold">Opened</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($cases as $case)
                        <tr class="hover:bg-stone-50">
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-stone-700">
                                <a href="{{ route('exco.disciplinary.show', $case) }}" class="hover:text-emerald-700">
                                    {{ $case->reference }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-stone-800">{{ $case->subjectLabel() }}</td>
                            <td class="px-4 py-3 text-stone-800">
                                <a href="{{ route('exco.disciplinary.show', $case) }}" class="hover:text-emerald-700">
                                    {{ $case->title }}
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $case->status->badgeClass() }}">
                                    {{ $case->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-stone-500">{{ $case->opened_at?->format('d M Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-sm text-stone-400">
                                No cases in this view.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $cases->links() }}</div>
    </div>
</x-layouts.app>
