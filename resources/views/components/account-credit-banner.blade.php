@auth
    @php
        $credit = auth()->user()->accountCreditSummary();
    @endphp
    @if($credit['posted'] > 0)
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <p class="font-semibold">Entry credit: R {{ number_format($credit['posted'], 2) }}</p>
            <p class="mt-1 text-emerald-800">From a cancelled event. This is applied automatically the next time you pay for a match. If you paid for a family member, the credit is on your account.</p>
            @if($credit['reserved'] > 0)
                <p class="mt-1 text-emerald-800">R {{ number_format($credit['reserved'], 2) }} is held for a checkout still in progress. Finish that payment, or open the entry and choose Pay again to release it.</p>
            @endif
        </div>
    @endif
@endauth
