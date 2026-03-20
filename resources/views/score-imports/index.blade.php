<x-layouts.app :title="'Score Imports'">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Score Imports</h1>

        <flux:button href="{{ route('score-imports.create') }}" variant="primary" icon="arrow-up-tray">
            Upload Scores
        </flux:button>
    </div>

    <div class="mt-6 border-t border-stone-200"></div>

    <div class="mt-6 rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
        <table class="min-w-full">
            <thead class="border-b-2 border-stone-200">
                <tr>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Source</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Filename</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Uploaded By</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</th>
                    <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($scoreImports as $import)
                    <tr class="border-b border-stone-100 hover:bg-stone-50 transition">
                        <td class="whitespace-nowrap px-5 py-4 text-sm font-medium text-stone-900">
                            <a href="{{ route('matches.show', $import->match) }}" class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $import->match->name }}</a>
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700 capitalize">{{ $import->source_type }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700">{{ $import->original_filename ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm">
                            @switch($import->status)
                                @case('pending')
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                    @break
                                @case('processing')
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Processing</span>
                                    @break
                                @case('completed')
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Completed</span>
                                    @break
                                @case('failed')
                                    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Failed</span>
                                    @break
                            @endswitch
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700">{{ $import->uploadedBy->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700">{{ $import->created_at->format('d M Y H:i') }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-right text-sm">
                            <flux:button href="{{ route('score-imports.show', $import) }}" variant="ghost" size="sm" icon="eye" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-sm text-stone-500">No score imports found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $scoreImports->withQueryString()->links() }}
    </div>
</x-layouts.app>
