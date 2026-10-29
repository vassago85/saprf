@if($match->supportsClubEntry() && ! in_array($match->status, ['completed', 'cancelled'], true))
    @php
        $clubEntryCandidates = app(\App\Services\RegistrationPricingService::class)
            ->unpaidNonMembersEligibleForClubEntry($match);
        $clubEntryPrice = $match->club_entry_fee !== null
            ? (float) $match->club_entry_fee
            : (float) $match->active_member_fee;
    @endphp
    @can('update', $match)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
            <p class="font-semibold">Unpaid club shooters</p>
            <p class="mt-1 text-xs leading-relaxed text-emerald-900">
                @if($clubEntryCandidates->isEmpty())
                    There are no unpaid non-members on this match. Paid shooters stay on the price already collected.
                @else
                    {{ $clubEntryCandidates->count() }} unpaid {{ \Illuminate\Support\Str::plural('non-member', $clubEntryCandidates->count()) }}
                    can move to the club entry price of <strong>R {{ number_format($clubEntryPrice, 2) }}</strong>.
                    Paid shooters are not changed. Save the club entry fee on Match settings first if this price is wrong.
                @endif
            </p>
            @if($clubEntryCandidates->isNotEmpty())
                <form method="POST" action="{{ route('matches.apply-club-entry', $match) }}" class="mt-3">
                    @csrf
                    <button type="submit"
                            class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800"
                            onclick="return confirm('Move {{ $clubEntryCandidates->count() }} unpaid non-member(s) onto the club entry price of R {{ number_format($clubEntryPrice, 2) }}? Paid entries stay as they are.');">
                        Move unpaid non-members to club entry
                    </button>
                </form>
            @endif
        </div>
    @endcan
@endif
