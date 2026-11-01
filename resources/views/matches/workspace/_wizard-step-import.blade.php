@php
    $afterDone = $afterDone ?? false;
@endphp

<div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
    <div class="flex items-start gap-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-700 text-white">
            <flux:icon.arrow-up-tray class="size-5" />
        </div>
        <div class="min-w-0 flex-1">
            <h2 class="font-heading text-lg font-bold text-stone-900">
                {{ $afterDone ? 'Imports on this match' : 'Step 1 · Import scores' }}
            </h2>
            <p class="mt-1 text-sm text-stone-600">
                @if($afterDone)
                    Keep a history of every import against this match. Re-importing replaces the previous
                    import but the audit record stays.
                @else
                    Pull live from Impact Scoring or upload a CSV. Once scores land you'll correct names
                    and divisions in the next step.
                @endif
            </p>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        <a href="{{ route('matches.impact-import', $match) }}" wire:navigate
           class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">
            <flux:icon.arrow-up-tray class="size-4" />
            Import from Impact
        </a>
        <a href="{{ route('score-imports.create', ['match_id' => $match->id, 'mode' => 'csv']) }}" wire:navigate
           class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-50">
            <flux:icon.arrow-up-tray class="size-4" />
            Upload CSV
        </a>
        <a href="{{ route('scores.entry', $match) }}" wire:navigate
           class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-50">
            <flux:icon.pencil-square class="size-4" />
            Enter manually
        </a>
        @if($hasAnyScores)
            <a href="{{ route('events.show', $match) }}#results" target="_blank"
               class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                <flux:icon.arrow-top-right-on-square class="size-4" />
                View public results
            </a>
        @endif
    </div>

    @if($imports->isEmpty())
        <div class="mt-6 rounded-lg border border-dashed border-stone-200 px-4 py-8 text-center text-sm text-stone-500">
            No imports yet. Export the entry list, score the match in Impact, then upload the CSV here.
        </div>
    @else
        <div class="mt-6 overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">Import</th>
                        <th class="px-3 py-2 text-left font-semibold">Uploader</th>
                        <th class="px-3 py-2 text-right font-semibold">Rows</th>
                        <th class="px-3 py-2 text-left font-semibold">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach($imports as $import)
                        <tr class="hover:bg-stone-50/60">
                            <td class="px-3 py-2">
                                <div class="font-medium text-stone-900">{{ $import->created_at?->format('j M Y H:i') }}</div>
                                <div class="text-xs text-stone-500">{{ $import->original_filename ?: 'manual' }}</div>
                            </td>
                            <td class="px-3 py-2 text-stone-700">{{ $import->uploader?->name ?: '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $import->scores_count }}</td>
                            <td class="px-3 py-2">
                                @php
                                    $statusColour = match ($import->import_status) {
                                        'completed' => 'bg-emerald-50 text-emerald-800',
                                        'queued', 'processing' => 'bg-amber-50 text-amber-800',
                                        'failed' => 'bg-red-50 text-red-700',
                                        default => 'bg-stone-100 text-stone-600',
                                    };
                                @endphp
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusColour }}">
                                    {{ \Illuminate\Support\Str::title($import->import_status ?? 'queued') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <a href="{{ route('score-imports.show', $import) }}" wire:navigate
                                   class="text-xs font-semibold text-emerald-700 hover:text-emerald-800">Open →</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
