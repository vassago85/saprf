<x-layouts.app :title="'My Membership'">
    <div class="max-w-2xl mx-auto space-y-6">
        <h1 class="font-heading text-3xl font-bold text-stone-900">My Membership</h1>

        @if($membership && $membership->payment_status === 'paid' && $membership->status === 'active')
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="inline-flex items-center justify-center size-10 rounded-lg bg-emerald-100 text-emerald-700 shrink-0">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </div>
                    <div>
                        <h2 class="font-heading text-lg font-bold text-emerald-900">Active SAPRF Member</h2>
                        <p class="text-sm text-emerald-700">Your membership is paid and active.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-lg bg-white/60 border border-emerald-200 p-4">
                        <p class="text-xs text-emerald-600 uppercase tracking-wider">SAPRF Number</p>
                        <p class="text-lg font-bold text-emerald-900 mt-1 font-mono">{{ $membership->saprf_number }}</p>
                    </div>
                    <div class="rounded-lg bg-white/60 border border-emerald-200 p-4">
                        <p class="text-xs text-emerald-600 uppercase tracking-wider">Expires</p>
                        <p class="text-lg font-bold text-emerald-900 mt-1">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</p>
                    </div>
                </div>
            </div>
        @elseif($membership && $membership->status === 'pending' && $membership->payment_status !== 'paid')
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="inline-flex items-center justify-center size-10 rounded-lg bg-amber-100 text-amber-700 shrink-0">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </div>
                    <div>
                        <h2 class="font-heading text-lg font-bold text-amber-900">Payment Pending</h2>
                        <p class="text-sm text-amber-800">Your membership has been created but payment is outstanding.</p>
                    </div>
                </div>

                <div class="rounded-lg bg-white/60 border border-amber-200 p-4 mb-4">
                    <p class="text-xs text-amber-600 uppercase tracking-wider">SAPRF Number (reserved)</p>
                    <p class="text-lg font-bold text-amber-900 mt-1 font-mono">{{ $membership->saprf_number }}</p>
                </div>

                @if($paymentsEnabled)
                    <div class="flex items-center justify-between rounded-lg bg-white border border-amber-200 p-4">
                        <div>
                            <p class="text-sm font-medium text-stone-900">Annual Membership Fee</p>
                            <p class="text-2xl font-bold text-stone-900 mt-1">R {{ number_format($fee, 2) }}</p>
                        </div>
                        <form method="POST" action="{{ route('payments.membership', $membership) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                                Pay Now
                            </button>
                        </form>
                    </div>
                @else
                    <p class="text-sm text-amber-700">Online payments are not currently enabled. Please contact the administrator.</p>
                @endif
            </div>
        @elseif($membership && $membership->isRevoked())
            <div class="rounded-xl border border-red-300 bg-red-50 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="inline-flex items-center justify-center size-10 rounded-lg bg-red-100 text-red-700 shrink-0">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                    </div>
                    <div>
                        <h2 class="font-heading text-lg font-bold text-red-900">Membership Revoked</h2>
                        <p class="text-sm text-red-800">Your SAPRF membership has been revoked.</p>
                    </div>
                </div>

                <div class="rounded-lg bg-white/60 border border-red-200 p-4 space-y-3">
                    <div>
                        <p class="text-xs text-red-600 uppercase tracking-wider">SAPRF Number</p>
                        <p class="text-lg font-bold text-red-900 mt-1 font-mono">{{ $membership->saprf_number }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-red-600 uppercase tracking-wider">Revoked On</p>
                        <p class="text-sm font-medium text-red-900 mt-1">{{ $membership->revoked_at->format('d M Y') }}</p>
                    </div>
                    @if($membership->revocation_reason)
                    <div>
                        <p class="text-xs text-red-600 uppercase tracking-wider">Reason</p>
                        <p class="text-sm text-red-800 mt-1">{{ $membership->revocation_reason }}</p>
                    </div>
                    @endif
                </div>

                <p class="text-sm text-red-700 mt-4">If you believe this is an error, please contact the SAPRF administration.</p>
            </div>
        @elseif($membership && in_array($membership->status, ['expired', 'lapsed']))
            <div class="rounded-xl border border-red-200 bg-red-50 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="inline-flex items-center justify-center size-10 rounded-lg bg-red-100 text-red-700 shrink-0">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    </div>
                    <div>
                        <h2 class="font-heading text-lg font-bold text-red-900">Membership {{ ucfirst($membership->status) }}</h2>
                        <p class="text-sm text-red-800">Your membership has {{ $membership->status }}. Renew to continue receiving full benefits.</p>
                    </div>
                </div>

                <div class="rounded-lg bg-white/60 border border-red-200 p-4 mb-4">
                    <p class="text-xs text-red-600 uppercase tracking-wider">SAPRF Number</p>
                    <p class="text-lg font-bold text-red-900 mt-1 font-mono">{{ $membership->saprf_number }}</p>
                    <p class="text-xs text-red-500 mt-1">Expired {{ $membership->expiry_date?->format('d M Y') }}</p>
                </div>

                @if($paymentsEnabled)
                    <div class="flex items-center justify-between rounded-lg bg-white border border-red-200 p-4">
                        <div>
                            <p class="text-sm font-medium text-stone-900">Renewal Fee</p>
                            <p class="text-2xl font-bold text-stone-900 mt-1">R {{ number_format($fee, 2) }}</p>
                        </div>
                        <form method="POST" action="{{ route('membership.join') }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                                Renew Membership
                            </button>
                        </form>
                    </div>
                @else
                    <p class="text-sm text-red-700">Online payments are not currently enabled. Please contact the administrator.</p>
                @endif
            </div>
        @else
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="inline-flex items-center justify-center size-10 rounded-lg bg-emerald-100 text-emerald-700 shrink-0">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z" /></svg>
                    </div>
                    <div>
                        <h2 class="font-heading text-lg font-bold text-stone-900">Become a SAPRF Member</h2>
                        <p class="text-sm text-stone-500">Join the South African Precision Rifle Federation.</p>
                    </div>
                </div>

                <div class="rounded-lg bg-stone-50 border border-stone-200 p-5 mb-6 space-y-3">
                    <p class="text-sm font-medium text-stone-900">Membership includes:</p>
                    <ul class="text-sm text-stone-600 space-y-2">
                        <li class="flex items-start gap-2">
                            <svg class="size-4 text-emerald-600 mt-0.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Official SAPRF number and national registration
                        </li>
                        <li class="flex items-start gap-2">
                            <svg class="size-4 text-emerald-600 mt-0.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Eligibility for national and provincial standings
                        </li>
                        <li class="flex items-start gap-2">
                            <svg class="size-4 text-emerald-600 mt-0.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Reduced match entry fees (no non-member surcharge)
                        </li>
                        <li class="flex items-start gap-2">
                            <svg class="size-4 text-emerald-600 mt-0.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            Qualification tracking for national finals
                        </li>
                    </ul>
                </div>

                @if($paymentsEnabled)
                    <div class="flex items-center justify-between rounded-lg bg-emerald-50 border border-emerald-200 p-5">
                        <div>
                            <p class="text-sm font-medium text-stone-700">Annual Membership Fee</p>
                            <p class="text-3xl font-bold text-stone-900 mt-1">R {{ number_format($fee, 2) }}</p>
                            <p class="text-xs text-stone-400 mt-1">Valid for 12 months from date of payment</p>
                        </div>
                        <form method="POST" action="{{ route('membership.join') }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                                Join & Pay Now
                            </button>
                        </form>
                    </div>
                @else
                    <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                        <p class="text-sm text-stone-600">Online payments are not currently enabled. Please contact the administrator to set up your membership.</p>
                    </div>
                @endif
            </div>
        @endif

        <div class="text-center">
            <a href="{{ route('dashboard') }}" class="text-sm font-medium text-stone-500 hover:text-stone-700">Back to Dashboard</a>
        </div>
    </div>
</x-layouts.app>
