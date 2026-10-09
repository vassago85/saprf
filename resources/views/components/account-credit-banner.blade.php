@auth
    @php
        $credit = auth()->user()->accountCreditSummary();
        $refundsOpen = app(\App\Services\SettingsService::class)->cancellationRefundsEnabled();
    @endphp
    @if($credit['posted'] > 0)
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            <p class="font-semibold">Entry credit: R {{ number_format($credit['posted'], 2) }}</p>
            <p class="mt-1 text-emerald-800">From a cancelled event or an entry the organisers removed. This is applied automatically the next time you pay for a match. If you paid for a family member, the credit is on your account.</p>
            @if(($credit['refund_reserved'] ?? 0) > 0)
                <p class="mt-1 text-emerald-800">R {{ number_format($credit['refund_reserved'], 2) }} is held for a refund request. <a href="{{ route('account.refund') }}" class="font-semibold underline">View the request</a></p>
            @endif
            @if(($credit['checkout_reserved'] ?? 0) > 0)
                <p class="mt-1 text-emerald-800">R {{ number_format($credit['checkout_reserved'], 2) }} is held for a checkout still in progress. Finish that payment, or open the entry and choose Pay again to release it.</p>
            @endif
            @if($refundsOpen && $credit['available'] > 0)
                <p class="mt-2"><a href="{{ route('account.refund') }}" class="font-semibold text-emerald-800 underline">Request a cash refund instead</a></p>
            @endif
        </div>
    @endif
@endauth
