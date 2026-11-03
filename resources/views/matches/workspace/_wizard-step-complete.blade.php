<div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
    <div class="flex items-start gap-3">
        <flux:icon.bell-alert class="mt-0.5 size-6 shrink-0 text-emerald-700" />
        <div class="flex-1">
            <h2 class="font-heading text-lg font-bold text-emerald-950">Step 4 · Mark scores complete</h2>
            <p class="mt-1 text-sm text-emerald-900">
                Everything is in and allocations are confirmed. Marking complete sends the
                "scores are up and verified" notification to shooters who opted in (email + push).
                Notifications only fire once — un-marking later keeps your mail-outs clean.
            </p>
        </div>
    </div>
</div>

<div class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-stone-900">
                {{ $scoresCount }} {{ \Illuminate\Support\Str::plural('score', $scoresCount) }}
                ready to publish
            </p>
            <p class="mt-1 text-xs text-stone-500">
                Public results already show these as provisional. Marking complete flips them to verified.
            </p>
            @if($wizard['has_walkins'])
                <p class="mt-1 text-xs text-emerald-700">
                    Walk-in fees acknowledged
                    @if($walkinAcknowledger)
                        by {{ $walkinAcknowledger->name }}
                    @endif
                    on {{ $match->walkin_fees_acknowledged_at->format('j M Y, H:i') }}.
                </p>
            @endif
        </div>
        @can('update', $match)
            <form method="POST" action="{{ route('matches.scores.complete', $match) }}"
                  onsubmit="return confirm('Mark these scores as complete and notify opted-in shooters? This fires emails and push notifications once.');">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                    <flux:icon.check class="size-4" />
                    Mark scores complete
                </button>
            </form>
        @endcan
    </div>

    @can('update', $match)
        <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-950">Need the payout before scores are final?</p>
            <p class="mt-1 text-xs leading-relaxed text-amber-800">
                You can request it now. If there are walk-ins, or anything else still outstanding,
                this payout can start off negative. Cash you collected stays with you. The SAPRF levy
                and the platform fee for those rows come off a later payout.
            </p>
            <a href="{{ route('matches.request-payout', $match) }}" wire:navigate
               class="mt-3 inline-flex items-center gap-1.5 rounded-md border border-amber-400 bg-white px-3 py-1.5 text-xs font-semibold text-amber-950 hover:bg-amber-100">
                <flux:icon.banknotes class="size-3.5" />
                Request payout now
            </a>
        </div>
    @endcan
</div>
