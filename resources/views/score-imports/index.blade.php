<x-layouts.app :title="'Score Imports'">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Score Imports</h1>

        <flux:button href="{{ route('score-imports.create') }}" variant="primary" icon="arrow-up-tray">
            Upload Scores
        </flux:button>
    </div>

    <div class="mt-6 border-t border-stone-200"></div>

    @if($scoreImports->isEmpty())
        <div class="mt-6">
            <x-empty-state
                heading="No score imports yet"
                description="Upload a scoring spreadsheet or CSV to record match results. Imports are validated and audited before scores go live."
                cta-label="Upload Scores"
                :cta-href="route('score-imports.create')">
                <x-slot:icon>
                    <svg class="h-6 w-6 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                    </svg>
                </x-slot:icon>
            </x-empty-state>
        </div>
    @else
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
                    @foreach ($scoreImports as $import)
                        <tr class="border-b border-stone-100 hover:bg-stone-50 transition">
                            <td class="whitespace-nowrap px-5 py-4 text-sm font-medium text-stone-900">
                                <a href="{{ route('matches.show', $import->match) }}" class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $import->match->name }}</a>
                            </td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700 capitalize">{{ $import->source_type }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700">{{ $import->original_filename ?? '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm">
                                @switch($import->import_status)
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
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700">{{ $import->uploader->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700">{{ $import->created_at->format('d M Y H:i') }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-right text-sm">
                                <flux:button href="{{ route('score-imports.show', $import) }}" variant="ghost" size="sm" icon="eye" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6">
            {{ $scoreImports->withQueryString()->links() }}
        </div>
    @endif
</x-layouts.app>
