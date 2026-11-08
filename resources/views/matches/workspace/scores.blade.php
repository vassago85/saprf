<x-layouts.app :title="$match->name . ' — Scores'">
    @include('matches.workspace._chrome')

    @php
        $current = $wizard['current'];
        $isDone = $current === \App\Services\MatchCloseoutWizard::STEP_DONE;
    @endphp

    {{-- Unpaid-shooter banner stays on every step of the wizard: the MD may want
         to chase payments at any point, independent of the close-out flow. --}}
    @can('update', $match)
        @if(($counts['pending_payment'] ?? 0) > 0)
            <div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-amber-950">
                            {{ $counts['pending_payment'] }} {{ \Illuminate\Support\Str::plural('shooter', $counts['pending_payment']) }}
                            still {{ $counts['pending_payment'] === 1 ? 'owes' : 'owe' }} an entry fee
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

    {{-- Live-sync hint (unchanged from the pre-wizard view). --}}
    @if($match->impact_live_sync && $match->impact_match_id)
        <div class="mt-6 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
            <p class="font-semibold">Live scores are on</p>
            <p class="mt-0.5 text-xs text-sky-800">
                Impact match {{ $match->impact_match_id }} is pulled every 20 minutes
                @if($match->isMultiDay())
                    from {{ $match->match_date->format('D j M') }} through {{ $match->match_end_date->format('D j M') }}.
                @else
                    on {{ $match->match_date?->format('D j M') }}.
                @endif
                @if($match->impact_live_ended_at)
                    Impact has marked it finished, so the pull has stopped.
                @elseif($match->impact_live_synced_at)
                    Last pull {{ $match->impact_live_synced_at->timezone('Africa/Johannesburg')->format('D j M, H:i') }}.
                @else
                    The first pull runs once the start date begins.
                @endif
                @if($match->impact_live_error)
                    Last attempt failed: {{ $match->impact_live_error }}
                @endif
            </p>
        </div>
    @endif

    {{-- Wizard stepper + the current step's panel. --}}
    @include('matches.workspace._wizard-stepper', ['wizard' => $wizard])

    <div class="mt-6">
        @switch($current)
            @case(\App\Services\MatchCloseoutWizard::STEP_IMPORT)
                @include('matches.workspace._wizard-step-import')
                @break

            @case(\App\Services\MatchCloseoutWizard::STEP_NAMES)
                @include('matches.workspace._wizard-step-names')
                @break

            @case(\App\Services\MatchCloseoutWizard::STEP_WALKINS)
                @include('matches.workspace._wizard-step-walkins')
                @break

            @case(\App\Services\MatchCloseoutWizard::STEP_COMPLETE)
                @include('matches.workspace._wizard-step-complete')
                @break

            @case(\App\Services\MatchCloseoutWizard::STEP_PAYOUT)
                @include('matches.workspace._wizard-step-payout')
                @break

            @default
                {{-- All five steps are done: the match is fully closed out. --}}
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6">
                    <div class="flex items-start gap-3">
                        <flux:icon.check-badge class="mt-0.5 size-6 text-emerald-700" />
                        <div class="flex-1">
                            <h2 class="font-heading text-lg font-semibold text-emerald-950">Match closed out</h2>
                            <p class="mt-1 text-sm text-emerald-800">
                                Scores are final, walk-in fees are acknowledged, and your payout
                                has been requested. There is nothing left to do on this match.
                            </p>
                            @if($wizard['payout'])
                                <div class="mt-3">
                                    <a href="{{ route('my-payouts.show', $wizard['payout']) }}"
                                       class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">
                                        <flux:icon.banknotes class="size-4" />
                                        View payout {{ $wizard['payout']->reference }}
                                    </a>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
        @endswitch
    </div>

    {{-- Persistent Imports footer: re-importing (because scores changed, or a
         CSV correction landed) is valid at any step, so the import links and
         the history table stay reachable regardless of the current step. --}}
    @if($current !== \App\Services\MatchCloseoutWizard::STEP_IMPORT)
        <div class="mt-6 rounded-xl border border-stone-200 bg-stone-50 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-stone-900">Imports on this match</h3>
                    <p class="mt-0.5 text-xs text-stone-500">
                        Re-importing replaces the previous import but the audit record stays.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('matches.impact-import', $match) }}" wire:navigate
                       class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                        <flux:icon.arrow-up-tray class="size-3.5" />
                        Import from Impact
                    </a>
                    <a href="{{ route('score-imports.create', ['match_id' => $match->id, 'mode' => 'csv']) }}" wire:navigate
                       class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                        <flux:icon.arrow-up-tray class="size-3.5" />
                        Upload CSV
                    </a>
                </div>
            </div>

            @if($imports->isNotEmpty())
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-sm">
                        <thead class="text-xs uppercase tracking-wide text-stone-500">
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
                                <tr>
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
    @endif
</x-layouts.app>
