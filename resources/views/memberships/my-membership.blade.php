<x-layouts.app :title="'My Membership'">
    <div class="max-w-2xl mx-auto space-y-6">
        <h1 class="font-heading text-3xl font-bold text-stone-900">{{ $managingFamily ? $user->name . "'s Membership" : 'My Membership' }}</h1>
        @if($managingFamily)
            <p class="text-sm text-stone-500 -mt-4">Managed from your account. <a href="{{ route('family.show', $user) }}" class="font-medium text-emerald-700 hover:underline">Back to {{ $user->name }}</a></p>
        @endif

        @if($membership?->isActiveMember())
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="inline-flex items-center justify-center size-10 rounded-lg bg-emerald-100 text-emerald-700 shrink-0">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </div>
                    <div>
                        <h2 class="font-heading text-lg font-bold text-emerald-900">Active SAPRF Member</h2>
                        <p class="text-sm text-emerald-700">{{ $managingFamily ? $user->name . "'s membership is paid and active." : 'Your membership is paid and active.' }}</p>
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

                {{-- Certificate & Activity Report --}}
                <div class="mt-5 pt-5 border-t border-emerald-200 space-y-4">
                    <a href="{{ route('membership.certificate', $managingFamily ? ['for_user' => $user->id] : []) }}"
                       class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                        Download Certificate
                    </a>

                    <div class="rounded-lg bg-white/60 border border-emerald-200 p-4" x-data="{ season: '{{ now()->year }}', includeStandings: false }">
                        <p class="text-sm font-semibold text-emerald-900 mb-3">Activity Report</p>
                        <p class="text-xs text-emerald-700 mb-3">Download a report of your match history for dedicated status applications or firearm licence submissions.</p>
                        <div class="flex flex-wrap items-end gap-3">
                            <div>
                                <label for="report-season" class="block text-xs font-medium text-emerald-700 mb-1">Season</label>
                                <select id="report-season" x-model="season" class="rounded-md border-emerald-300 text-sm py-1.5 px-3 focus:border-emerald-500 focus:ring-emerald-500">
                                    @foreach($seasons as $s)
                                        <option value="{{ $s }}">{{ $s }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label class="flex items-center gap-2 text-sm text-emerald-800 cursor-pointer">
                                <input type="checkbox" x-model="includeStandings" class="rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500">
                                Include standings
                            </label>
                            <a :href="`{{ route('membership.activity-report', $managingFamily ? ['for_user' => $user->id] : []) }}?season=${season}&include_standings=${includeStandings ? 1 : 0}`"
                               class="inline-flex items-center gap-2 rounded-lg bg-stone-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-stone-900 focus:outline-none focus:ring-2 focus:ring-stone-500 focus:ring-offset-2">
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                Download Report
                            </a>
                        </div>
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
                        <p class="text-sm text-amber-800">{{ $managingFamily ? $user->name . "'s membership has been created but payment is outstanding." : 'Your membership has been created but payment is outstanding.' }}</p>
                    </div>
                </div>

                <div class="rounded-lg bg-white/60 border border-amber-200 p-4 mb-4">
                    <p class="text-xs text-amber-600 uppercase tracking-wider">SAPRF Number (reserved)</p>
                    <p class="text-lg font-bold text-amber-900 mt-1 font-mono">{{ $membership->saprf_number }}</p>
                </div>

                @if($paymentsEnabled)
                    <div class="flex items-center justify-between rounded-lg bg-white border border-amber-200 p-4">
                        <div>
                            <p class="text-sm font-medium text-stone-900">{{ $membership->feeTier?->name ? $membership->feeTier->name . ' Membership' : 'Annual Membership Fee' }}</p>
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
                    @include('memberships._fee-tier-select', [
                        'action' => route('membership.join'),
                        'buttonLabel' => 'Renew Membership',
                        'hidden' => $managingFamily ? ['for_user' => $user->id] : [],
                    ])
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
                        <h2 class="font-heading text-lg font-bold text-stone-900">{{ $managingFamily ? 'SAPRF membership for ' . $user->name : 'Become a SAPRF Member' }}</h2>
                        <p class="text-sm text-stone-500">{{ $managingFamily ? 'Pay from your account — they do not need their own login.' : 'Join the South African Precision Rifle Federation.' }}</p>
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
                    @include('memberships._fee-tier-select', [
                        'action' => route('membership.join'),
                        'buttonLabel' => 'Join & Pay Now',
                        'hidden' => $managingFamily ? ['for_user' => $user->id] : [],
                    ])
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
