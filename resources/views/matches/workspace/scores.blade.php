<x-layouts.app :title="$match->name . ' — Scores'">
    @include('matches.workspace._chrome')

    @php
        $scoresFinalized = $match->scoresAreFinalized();
        $notifiedAt = $match->scores_finalized_notified_at;
    @endphp

    @can('update', $match)
        @if(($counts['pending_payment'] ?? 0) > 0)
            <div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-amber-950">
                            {{ $counts['pending_payment'] }} {{ \Illuminate\Support\Str::plural('shooter', $counts['pending_payment']) }} still {{ $counts['pending_payment'] === 1 ? 'owes' : 'owe' }} an entry fee
                        </p>
                        <p class="mt-0.5 text-xs text-amber-800">Send the payment email from here. Each shooter can pay now or reply with proof of payment.</p>
                    </div>
                    <form method="POST" action="{{ route('matches.notify-unpaid', $match) }}">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-md bg-amber-500 px-3 py-1.5 text-sm font-semibold text-amber-950 hover:bg-amber-400"
                                onclick="return confirm('Email all unpaid shooters on this match now?');">
                            Email unpaid shooters
                        </button>
                    </form>
                </div>
            </div>
        @endif
    @endcan

    @if($hasAnyScores)
        <div class="mt-6 rounded-xl border p-4 {{ $scoresFinalized ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    @if($scoresFinalized)
                        <div class="flex items-center gap-2 text-sm font-semibold text-emerald-900">
                            <flux:icon.check-badge class="size-5 text-emerald-700" />
                            Scores marked complete
                            @if($notifiedAt)
                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">
                                    Shooters notified
                                </span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-emerald-800">
                            Finalised {{ $match->scores_finalized_at?->diffForHumans() }}
                            @if($match->scoresFinalizer)
                                by {{ $match->scoresFinalizer->name }}
                            @endif.
                            Shooters who opted in have been (or will shortly be) emailed and pushed.
                        </p>
                    @else
                        <div class="flex items-center gap-2 text-sm font-semibold text-amber-900">
                            <flux:icon.bell-alert class="size-5 text-amber-700" />
                            Scores imported — not yet marked complete
                        </div>
                        <p class="mt-1 text-xs text-amber-800">
                            Results are already visible on the public page as provisional. Shooters
                            will <strong>only</strong> be notified once you confirm the names and
                            entries are checked.
                        </p>
                    @endif
                </div>
                <div class="shrink-0">
                    @if($scoresFinalized)
                        <form method="POST" action="{{ route('matches.scores.uncomplete', $match) }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-emerald-300 bg-white px-3 py-1.5 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
                                <flux:icon.arrow-uturn-left class="size-4" />
                                Unmark
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('matches.scores.complete', $match) }}"
                              onsubmit="return confirm('Mark these scores as complete and notify opted-in shooters? This fires emails and push notifications once.');">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                                <flux:icon.check class="size-4" />
                                Mark scores complete
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-stone-200 bg-white p-5 lg:col-span-2">
            <h3 class="text-sm font-semibold text-stone-900">Scores</h3>
            <p class="mt-1 text-sm text-stone-500">
                One place to get scores in: import from Impact Scoring or enter manually. Re-importing
                replaces the previous import and keeps a history.
            </p>

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
                        View results
                    </a>
                @endif
            </div>

            @if($imports->isEmpty())
                <div class="mt-6 rounded-lg border border-dashed border-stone-200 px-4 py-8 text-center text-sm text-stone-500">
                    No imports yet. Export the entry list, score the match in Impact,
                    then upload the CSV here.
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

        <div class="rounded-xl border border-stone-200 bg-white p-5">
            <h3 class="text-sm font-semibold text-stone-900">Checklist</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @php
                    $items = [
                        'entries_exported' => 'Entry list exported to Impact',
                        'scores_imported' => 'Scores imported',
                        'all_shooters_matched' => 'All shooters matched',
                        'results_published' => 'Results published',
                        'scores_finalized' => 'Scores marked complete (shooters notified)',
                    ];
                @endphp
                @foreach($items as $key => $label)
                    @php $done = (bool) ($checklist[$key] ?? false); @endphp
                    <li class="flex items-center gap-2 {{ $done ? 'text-stone-700' : 'text-stone-500' }}">
                        @if($done)
                            <flux:icon.check-circle class="size-5 text-emerald-600" />
                        @else
                            <span class="size-5 rounded-full border-2 border-stone-200"></span>
                        @endif
                        {{ $label }}
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-layouts.app>
