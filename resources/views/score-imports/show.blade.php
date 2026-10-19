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

    @if (($audit ?? []) !== [])
        @php
            $reasonMeta = [
                'pending_unpaid' => ['label' => 'Unpaid / pending', 'tone' => 'amber', 'hint' => 'Send inquiry or mark settled when POP arrives'],
                'walk_in_member' => ['label' => 'Member walk-in', 'tone' => 'sky', 'hint' => 'Not on entry list — collect fee (deducted from MD payout)'],
                'assign_later' => ['label' => 'Assign later', 'tone' => 'violet', 'hint' => 'Stub score — link a member below when confirmed'],
                'skipped_fuzzy' => ['label' => 'Skipped (fuzzy)', 'tone' => 'stone', 'hint' => 'Name not confirmed at import'],
                'skipped' => ['label' => 'Skipped', 'tone' => 'stone', 'hint' => 'MD chose skip'],
                'non_member_stub' => ['label' => 'Non-member', 'tone' => 'stone', 'hint' => 'Visible on match, does not count for season'],
            ];
            $toneClasses = [
                'amber' => 'bg-amber-50 border-amber-200 text-amber-900',
                'sky' => 'bg-sky-50 border-sky-200 text-sky-900',
                'violet' => 'bg-violet-50 border-violet-200 text-violet-900',
                'stone' => 'bg-stone-50 border-stone-200 text-stone-700',
            ];
        @endphp
        <div class="mt-6 overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
            <div class="border-b border-stone-100 bg-gradient-to-r from-stone-900 via-stone-800 to-emerald-900 px-6 py-5 text-white">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-200/90">Post-import audit</p>
                        <h2 class="mt-1 font-heading text-xl font-bold tracking-tight">Who needs attention</h2>
                        <p class="mt-1 text-sm text-stone-300">Unpaid entries, walk-ins, deferred links, skips, and non-members — kept for the record.</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        @foreach ($auditSummary ?? [] as $key => $n)
                            @if ($n > 0)
                                <span class="rounded-full bg-white/10 px-2.5 py-1 font-semibold ring-1 ring-white/15">
                                    {{ $reasonMeta[$key]['label'] ?? $key }} · {{ $n }}
                                </span>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
            <ul class="divide-y divide-stone-100">
                @foreach ($audit as $row)
                    @php
                        $reason = $row['reason'] ?? 'other';
                        $meta = $reasonMeta[$reason] ?? ['label' => $reason, 'tone' => 'stone', 'hint' => ''];
                        $tone = $toneClasses[$meta['tone']] ?? $toneClasses['stone'];
                    @endphp
                    <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4">
                        <div class="min-w-0">
                            <p class="font-semibold text-stone-900">{{ $row['name'] ?? 'Shooter' }}</p>
                            <p class="mt-0.5 text-xs text-stone-500">
                                @if (!empty($row['impact_division']))
                                    Impact {{ $row['impact_division'] }}
                                @endif
                                @if (isset($row['impacts']))
                                    · {{ $row['impacts'] }} hits
                                @endif
                                @if (!empty($row['payment_status']))
                                    · payment {{ $row['payment_status'] }}
                                @endif
                            </p>
                            @if ($meta['hint'])
                                <p class="mt-1 text-xs text-stone-500">{{ $meta['hint'] }}</p>
                            @endif
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold {{ $tone }}">
                                {{ $meta['label'] }}
                            </span>
                            @if ((float) ($row['fee_amount'] ?? 0) > 0)
                                <span class="text-xs font-semibold tabular-nums text-amber-800">
                                    Collect R{{ number_format((float) $row['fee_amount'], 2) }}
                                </span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (($pendingLinkScores ?? collect())->isNotEmpty())
        <div class="mt-6 rounded-2xl border border-violet-200 bg-gradient-to-br from-violet-50/80 via-white to-white p-6 shadow-sm">
            <div class="flex items-start gap-3 mb-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-700 text-white">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                </div>
                <div>
                    <h2 class="font-heading text-lg font-bold text-violet-950">Link to entry</h2>
                    <p class="mt-0.5 text-sm text-violet-900/80">These Impact scores imported as stubs and do not count until you attach the real <strong>match entry</strong> — members and non-members. Entry division wins (e.g. Senior / Junior over Impact Open).</p>
                </div>
            </div>
            @if ($linkRegistrations->isEmpty())
                <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">No active entries on this match to link to. Add the shooter on the registrations list first (member or non-member walk-in), then return here.</p>
            @endif
            <ul class="space-y-3">
                @foreach ($pendingLinkScores as $pending)
                    <li class="rounded-xl border border-violet-200/80 bg-white px-4 py-3">
                        <form method="POST" action="{{ route('scores.link-impact-member', $pending) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-stone-900">{{ $pending->shooter_name }}</p>
                                <p class="text-xs text-stone-500 mt-0.5">
                                    Impact name · {{ number_format((float) ($pending->raw_score ?? $pending->day1_raw_score ?? 0), 1) }} hits
                                    · {{ $pending->status }}
                                </p>
                            </div>
                            <div class="w-full sm:w-80">
                                <label class="block text-[11px] font-semibold uppercase tracking-wider text-stone-400 mb-1">Match entry</label>
                                <select name="registration_id" required
                                        class="w-full rounded-lg border border-violet-300 text-sm py-2 focus:ring-violet-500 focus:border-violet-500">
                                    <option value="">Select entry…</option>
                                    @foreach ($linkRegistrations as $reg)
                                        @php
                                            $entryLabel = $reg->user?->name ?: $reg->shooter_name ?: 'Unnamed entry';
                                            $cat = $reg->feeCategoryLabel();
                                        @endphp
                                        <option value="{{ $reg->id }}">
                                            {{ $entryLabel }}
                                            · {{ $cat }}
                                            @if ($reg->division) · {{ $reg->division->name }} @endif
                                            · {{ $reg->payment_status ?? '—' }}
                                            @if (! $reg->user_id) · name-only @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-xl bg-violet-700 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-800 transition">
                                Link to entry
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($canRequestMdPayout)
        @php $alreadyCompleted = $scoreImport->match->status === 'completed'; @endphp
        <div
            x-data="{ dismissed: false }"
            x-show="!dismissed"
            class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-5"
        >
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 mt-0.5">
                    <svg class="h-6 w-6 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-base font-semibold text-emerald-900">
                        @if ($alreadyCompleted)
                            Match closed — request your payout?
                        @else
                            Scores are in — close out this match?
                        @endif
                    </h3>
                    <p class="mt-1 text-sm text-emerald-800">
                        @if ($alreadyCompleted)
                            <span class="font-medium">{{ $scoreImport->match->name }}</span> was
                            auto-completed when the scores landed. File your match-director payout
                            request now — an admin will review it and settle payment.
                        @else
                            Mark <span class="font-medium">{{ $scoreImport->match->name }}</span> as
                            <span class="font-medium">completed</span> and file your match-director payout request in one step.
                            An admin will review it and settle payment.
                        @endif
                    </p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('matches.complete-and-request-payout', $scoreImport->match) }}">
                            @csrf
                            <button type="submit"
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                                {{ $alreadyCompleted ? 'Request match director payout' : 'Complete match & request payout' }}
                            </button>
                        </form>
                        <button type="button" @click="dismissed = true"
                            class="text-sm font-medium text-emerald-800 hover:text-emerald-900 underline underline-offset-2">
                            Not yet
                        </button>
                    </div>
                </div>
            </div>
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

    @if ($unconfirmedZeroScores->isNotEmpty())
        <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 mt-0.5">
                    <svg class="h-6 w-6 text-amber-700" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.732 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-base font-semibold text-amber-900">
                        Please confirm the shooters below actually participated
                    </h3>
                    <p class="mt-1 text-sm text-amber-800">
                        {{ $unconfirmedZeroScores->count() }} {{ Str::plural('shooter', $unconfirmedZeroScores->count()) }}
                        on this import scored <span class="font-medium">0</span>.
                        A zero can mean they genuinely zeroed the match, or that they were listed on the score sheet by mistake and never turned up. Confirm each row so results and season logs stay honest.
                    </p>
                    <div class="mt-4 space-y-2">
                        @foreach ($unconfirmedZeroScores as $zeroScore)
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-white px-4 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-stone-900">
                                        {{ $zeroScore->user->name ?? $zeroScore->shooter_name ?? '—' }}
                                    </p>
                                    <p class="text-xs text-stone-500">
                                        {{ $zeroScore->division?->name ?? 'No division' }}
                                        @if ($zeroScore->placement)
                                            · Listed placement {{ $zeroScore->placement }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <form method="POST" action="{{ route('score-imports.scores.confirm-participation', ['scoreImport' => $scoreImport, 'score' => $zeroScore]) }}">
                                        @csrf
                                        <button type="submit"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800 transition">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                            Yes, participated
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('score-imports.scores.mark-absent', ['scoreImport' => $scoreImport, 'score' => $zeroScore]) }}"
                                          onsubmit="return confirm('Remove this shooter from the match results? Their score row will be deleted.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50 transition">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                            No, remove
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
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
