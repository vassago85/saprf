<x-layouts.app :title="'Membership: ' . $membership->user->name">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Membership Details</h1>

        <div class="flex items-center gap-2">
            <a href="{{ route('memberships.edit', $membership) }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                Edit
            </a>
            <a href="{{ route('memberships.index') }}" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Back
            </a>
        </div>
    </div>

    <div class="mt-8 max-w-3xl space-y-6">
        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Member Information</h2>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Name</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $membership->user->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Email</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $membership->user->email }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">SAPRF Number</dt>
                    <dd class="mt-1 text-sm font-mono text-stone-900">{{ $membership->saprf_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Type</dt>
                    <dd class="mt-1 text-sm text-stone-900 capitalize">{{ $membership->membership_type }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Status</dt>
                    <dd class="mt-1.5">
                        @switch($membership->status)
                            @case('active')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                                @break
                            @case('pending')
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                @break
                            @case('lapsed')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Lapsed</span>
                                @break
                            @case('suspended')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Suspended</span>
                                @break
                        @endswitch
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Payment Status</dt>
                    <dd class="mt-1.5">
                        @switch($membership->payment_status)
                            @case('paid')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Paid</span>
                                @break
                            @case('pending')
                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                @break
                            @case('overdue')
                                <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Overdue</span>
                                @break
                        @endswitch
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Start Date</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $membership->start_date?->format('d M Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-stone-400">Expiry Date</dt>
                    <dd class="mt-1 text-sm text-stone-900">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        @if ($membership->payments->count())
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-semibold text-stone-900 mb-5">Payment History</h2>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b-2 border-stone-200">
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Date</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Method</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Reference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($membership->payments as $payment)
                                <tr class="hover:bg-stone-50 transition-colors">
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-stone-900">{{ $payment->created_at->format('d M Y') }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-right font-mono text-stone-900">R {{ number_format($payment->amount, 2) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-stone-500 capitalize">{{ $payment->payment_method ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-stone-500">{{ $payment->payment_reference ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
