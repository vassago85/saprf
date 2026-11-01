<div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5">
    <div class="flex items-start gap-3">
        <flux:icon.banknotes class="mt-0.5 size-6 shrink-0 text-emerald-700" />
        <div class="flex-1">
            <h2 class="font-heading text-lg font-bold text-emerald-950">Step 5 · Request your payout</h2>
            <p class="mt-1 text-sm text-emerald-900">
                Scores are complete and your fees are acknowledged. File your match-director payout
                request — an admin will review and settle payment.
            </p>
        </div>
    </div>
</div>

<div class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-stone-900">
                Scores marked complete
                @if($match->scores_finalized_at)
                    {{ $match->scores_finalized_at->diffForHumans() }}
                @endif
                @if($match->scoresFinalizer)
                    by {{ $match->scoresFinalizer->name }}
                @endif
            </p>
            @if($wizard['has_walkins'])
                <p class="mt-1 text-xs text-stone-500">
                    Walk-in fees acknowledged — the deduction is already baked into the payout math on the next page.
                </p>
            @endif
            <div class="mt-3 flex flex-wrap gap-2">
                @can('update', $match)
                    <form method="POST" action="{{ route('matches.scores.uncomplete', $match) }}">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                            <flux:icon.arrow-uturn-left class="size-3.5" />
                            Unmark (edit again)
                        </button>
                    </form>
                @endcan
            </div>
        </div>
        @can('update', $match)
            <a href="{{ route('matches.request-payout', $match) }}" wire:navigate
               class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                <flux:icon.banknotes class="size-4" />
                Enter banking details & request payout
            </a>
        @endcan
    </div>
</div>
