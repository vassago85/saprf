<x-layouts.app :title="'Score Import #' . $scoreImport->id">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Score Import #{{ $scoreImport->id }}</h1>
        <flux:button href="{{ route('score-imports.index') }}" variant="ghost" icon="arrow-left">Back</flux:button>
    </div>

    <div class="mt-6 border-t border-stone-200"></div>

    <div class="mt-6 rounded-xl border border-stone-200 bg-white shadow-sm p-6">
        <h2 class="text-lg font-semibold text-stone-900 mb-4">Import Details</h2>

        <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</dt>
                <dd class="mt-1.5 text-sm">
                    <a href="{{ route('matches.show', $scoreImport->match) }}" class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $scoreImport->match->name }}</a>
                </dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Source Type</dt>
                <dd class="mt-1.5 text-sm text-stone-900 capitalize">{{ $scoreImport->source_type }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Filename</dt>
                <dd class="mt-1.5 text-sm text-stone-900">{{ $scoreImport->original_filename ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</dt>
                <dd class="mt-1.5">
                    @switch($scoreImport->import_status)
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
                </dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Uploaded By</dt>
                <dd class="mt-1.5 text-sm text-stone-900">{{ $scoreImport->uploader->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</dt>
                <dd class="mt-1.5 text-sm text-stone-900">{{ $scoreImport->created_at->format('d M Y H:i') }}</dd>
            </div>
            @if ($scoreImport->error_message)
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-stone-400">Error</dt>
                    <dd class="mt-1.5 text-sm text-red-700">{{ $scoreImport->error_message }}</dd>
                </div>
            @endif
        </dl>
    </div>

    @if ($scoreImport->notes)
        <div class="mt-6 rounded-xl border {{ $scoreImport->import_status === 'failed' ? 'border-red-200 bg-red-50' : 'border-stone-200 bg-stone-50' }} p-4">
            <h3 class="text-sm font-semibold text-stone-900 mb-2">Import Log</h3>
            <pre class="whitespace-pre-wrap text-xs {{ $scoreImport->import_status === 'failed' ? 'text-red-800' : 'text-stone-700' }} font-mono">{{ $scoreImport->notes }}</pre>
        </div>
    @endif

    @if ($scoreImport->import_status === 'processing' || $scoreImport->import_status === 'queued' || $scoreImport->import_status === 'pending')
        <div class="mt-6 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
            <div class="flex items-center gap-2">
                <svg class="animate-spin h-4 w-4 text-sky-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <span class="font-medium">Processing in the background…</span>
            </div>
            <p class="mt-1 text-xs text-sky-700">Refresh this page in a few seconds to see progress. Standings will be recalculated automatically once the import completes.</p>
            <script>setTimeout(() => window.location.reload(), 4000);</script>
        </div>
    @endif

    @if ($scores->count())
        <div class="mt-6 rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="px-6 pt-6 pb-4">
                <h2 class="text-lg font-semibold text-stone-900">Imported Scores ({{ $scores->total() }})</h2>
            </div>

            <table class="min-w-full">
                <thead class="border-b-2 border-stone-200">
                    <tr>
                        <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Shooter</th>
                        <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Division</th>
                        <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Impacts</th>
                        <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Placement</th>
                        <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scores as $score)
                        <tr class="border-b border-stone-100 hover:bg-stone-50 transition">
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-900">{{ $score->user->name ?? $score->shooter_name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700">{{ $score->division?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-right text-stone-900 font-mono font-medium">{{ $score->raw_score }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm text-right text-stone-700">{{ $score->placement ?? '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-sm">
                                @if ($score->status === 'valid')
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Valid</span>
                                @elseif ($score->status === 'pending')
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">{{ ucfirst($score->status) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-4 border-t border-stone-100">
                {{ $scores->links() }}
            </div>
        </div>
    @endif
</x-layouts.app>
