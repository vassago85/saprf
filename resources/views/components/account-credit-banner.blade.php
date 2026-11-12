@auth
    @php
        $credit = auth()->user()->accountCreditSummary();
        $refundsOpen = app(\App\Services\SettingsService::class)->cancellationRefundsEnabled();
        $postedCents = (int) round(((float) $credit['posted']) * 100);
        $dismissKey = 'saprf.credit-banner-dismissed.'.auth()->id().'.'.((int) ($credit['latest_posted_id'] ?? 0)).'.'.$postedCents;
    @endphp
    @if($credit['posted'] > 0)
        {{--
            Shown on every signed-in page while posted credit remains.
            Dismiss hides it on this browser until the balance changes
            (a new credit row, or a different posted amount). The credit
            itself is untouched and still applies at the next payment.
            The check runs before the banner is parsed so a dismissed
            notice does not flash on the next page, including Livewire
            navigations that keep the <html> element.
        --}}
        <script>
            window.saprfDismissCreditBanner = function (key) {
                try { localStorage.setItem(key, '1'); } catch (e) {}
                document.documentElement.setAttribute('data-credit-banner-hidden', '1');
            };
            try {
                if (localStorage.getItem(@js($dismissKey)) === '1') {
                    document.documentElement.setAttribute('data-credit-banner-hidden', '1');
                } else {
                    document.documentElement.removeAttribute('data-credit-banner-hidden');
                }
            } catch (e) {}
        </script>
        <style>
            html[data-credit-banner-hidden="1"] [data-credit-banner] { display: none !important; }
        </style>
        <div data-credit-banner class="relative mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 pr-12 text-sm text-emerald-900">
            <button type="button"
                data-dismiss-key="{{ $dismissKey }}"
                onclick="saprfDismissCreditBanner(this.dataset.dismissKey)"
                class="absolute right-3 top-3 rounded-lg p-1 text-emerald-800 hover:bg-emerald-100"
                aria-label="Dismiss entry credit notice">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-4" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
            <p class="font-semibold">Entry credit: R {{ number_format($credit['posted'], 2) }}</p>
            <p class="mt-1 text-emerald-800">From a cancelled event, a withdrawn or removed entry, or a reduced surcharge. This is applied automatically the next time you pay for a match. If you paid for a family member, the credit is on your account.</p>
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
