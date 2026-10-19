<x-layouts.app :title="'My Invoices'">
    <div class="max-w-4xl mx-auto space-y-6">
        <x-page-header title="My Invoices" subtitle="Pay outstanding match entries, and download PDFs for payments you've already made.">
            <x-slot:actions>
                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                    Back
                </a>
            </x-slot:actions>
        </x-page-header>

        @if(($outstandingRegistrations ?? collect())->isNotEmpty())
            <div class="rounded-xl border border-red-200 bg-red-50/70 p-5 shadow-sm space-y-4">
                <div>
                    <h2 class="font-heading text-lg font-semibold text-red-900">Payment outstanding</h2>
                    <p class="mt-1 text-sm text-red-800/90">These match entries still need payment. Pay securely through the portal, or send your match director a POP / PayFast confirmation screenshot.</p>
                </div>
                <ul class="space-y-3">
                    @foreach ($outstandingRegistrations as $registration)
                        <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-200/80 bg-white px-4 py-3">
                            <div class="min-w-0">
                                <p class="font-medium text-stone-900">{{ $registration->match?->name ?? 'Match entry' }}</p>
                                <p class="text-xs text-stone-500 mt-0.5">
                                    {{ $registration->match?->match_date?->format('d M Y') ?? '—' }}
                                    @if($registration->division)
                                        · {{ $registration->division->name }}
                                    @endif
                                    · R{{ number_format((float) $registration->fee_amount, 2) }}
                                </p>
                            </div>
                            <a href="{{ route('registrations.show', $registration) }}"
                               class="shrink-0 inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                                Pay now
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($payments->isEmpty())
            @if(($outstandingRegistrations ?? collect())->isEmpty())
                <div class="rounded-xl border border-stone-200 bg-white p-10 text-center shadow-sm">
                    <div class="mx-auto inline-flex items-center justify-center size-12 rounded-full bg-stone-100 text-stone-500 mb-4">
                        <svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>
                    </div>
                    <h2 class="font-heading text-lg font-semibold text-stone-900">No invoices yet</h2>
                    <p class="mt-1 text-sm text-stone-500">Invoices appear here as soon as you complete a membership or match entry payment.</p>
                    <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
                        <a href="{{ route('events.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                            Browse upcoming events
                        </a>
                        <a href="{{ route('my-membership') }}" class="inline-flex items-center gap-2 rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                            My membership
                        </a>
                    </div>
                </div>
            @else
                <p class="text-sm text-stone-500">Paid invoices will appear here after you complete a payment on the portal.</p>
            @endif
        @else
            <x-admin-table>
                {{-- Table (sm+) --}}
                <table class="hidden min-w-full divide-y divide-stone-200 sm:table">
                    <thead class="bg-stone-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">Date</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">Description</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">Reference</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-stone-500">Amount</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-stone-500">Invoice</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($payments as $payment)
                            @php
                                $payable = $payment->payable;
                                $isRegistration = $payable instanceof \App\Models\MatchRegistration;
                                $isMembership = $payable instanceof \App\Models\Membership;
                                $description = match (true) {
                                    $isRegistration && $payable->match => 'Match entry — '.$payable->match->name,
                                    $isRegistration => 'Match entry',
                                    $isMembership => 'SAPRF Membership'.($payable->feeTier?->name ? ' — '.$payable->feeTier->name : ''),
                                    default => 'SAPRF transaction',
                                };
                                $detail = match (true) {
                                    $isRegistration => $payable->match?->match_date?->format('d M Y'),
                                    $isMembership && $payable->expiry_date => 'Expires '.$payable->expiry_date->format('d M Y'),
                                    default => null,
                                };
                                $isForAnotherAccount = $payment->user_id !== auth()->id();
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-stone-700">
                                    {{ $payment->paid_at?->format('d M Y') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-sm text-stone-900">
                                    <div class="font-medium">{{ $description }}</div>
                                    @if($detail)
                                        <div class="text-xs text-stone-500 mt-0.5">{{ $detail }}</div>
                                    @endif
                                    @if($isForAnotherAccount && $payment->user)
                                        <div class="text-xs text-stone-500 mt-0.5">Paid for {{ $payment->user->name }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs font-mono text-stone-500">
                                    {{ $payment->m_payment_id }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold text-stone-900">
                                    R {{ number_format((float) $payment->amount, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="{{ route('payments.invoice', $payment) }}"
                                       class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                        Download
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- Stacked cards (xs) --}}
                <ul class="divide-y divide-stone-100 sm:hidden">
                    @foreach($payments as $payment)
                        @php
                            $payable = $payment->payable;
                            $isRegistration = $payable instanceof \App\Models\MatchRegistration;
                            $isMembership = $payable instanceof \App\Models\Membership;
                            $description = match (true) {
                                $isRegistration && $payable->match => 'Match entry — '.$payable->match->name,
                                $isRegistration => 'Match entry',
                                $isMembership => 'SAPRF Membership'.($payable->feeTier?->name ? ' — '.$payable->feeTier->name : ''),
                                default => 'SAPRF transaction',
                            };
                            $isForAnotherAccount = $payment->user_id !== auth()->id();
                        @endphp
                        <li class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-medium text-stone-900">{{ $description }}</div>
                                    <div class="text-xs text-stone-500 mt-0.5">
                                        {{ $payment->paid_at?->format('d M Y') ?? '—' }}
                                        @if($isForAnotherAccount && $payment->user)
                                            &middot; Paid for {{ $payment->user->name }}
                                        @endif
                                    </div>
                                    <div class="text-xs font-mono text-stone-400 mt-1 break-all">{{ $payment->m_payment_id }}</div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="text-sm font-semibold text-stone-900">R {{ number_format((float) $payment->amount, 2) }}</div>
                                </div>
                            </div>
                            <a href="{{ route('payments.invoice', $payment) }}"
                               class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                Download invoice
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-admin-table>

            @if($payments->hasPages())
                <div class="pt-2">
                    {{ $payments->links() }}
                </div>
            @endif
        @endif
    </div>
</x-layouts.app>
