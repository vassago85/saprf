<x-layouts.app :title="'Payouts - SAPRF'">
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <a href="{{ route('financials.dashboard') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Dashboard</a>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">Payouts</h1>
                <p class="mt-1 text-sm text-stone-500">Track settlements to match directors and SAPRF revenue.</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('financials.payouts.create') }}"
                   class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Create Payout
                </a>
                <a href="{{ route('financials.export.payouts-csv') }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-stone-100 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">
                    Export CSV
                </a>
            </div>
        </div>

        {{-- Summary --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-xl border border-amber-200 bg-amber-50 shadow-sm p-5">
                <p class="text-xs font-medium text-amber-700 uppercase">Pending</p>
                <p class="mt-1 text-2xl font-bold text-amber-800">R{{ number_format($pendingTotal, 2) }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 shadow-sm p-5">
                <p class="text-xs font-medium text-emerald-700 uppercase">Total Paid</p>
                <p class="mt-1 text-2xl font-bold text-emerald-800">R{{ number_format($paidTotal, 2) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Total Tracked</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">{{ $payouts->total() }}</p>
            </div>
        </div>

        {{-- Filters --}}
        <div class="flex gap-2">
            <a href="{{ route('financials.payouts') }}"
               class="rounded-lg px-3 py-1.5 text-sm font-medium {{ !$status ? 'bg-stone-800 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }} transition">
                All
            </a>
            @foreach(['pending', 'partial', 'paid'] as $s)
            <a href="{{ route('financials.payouts', ['status' => $s]) }}"
               class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $status === $s ? 'bg-stone-800 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }} transition">
                {{ ucfirst($s) }}
            </a>
            @endforeach
        </div>

        {{-- Payouts Table --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs uppercase text-stone-500">
                            <th class="py-3 px-4">Reference</th>
                            <th class="py-3 px-4">Type</th>
                            <th class="py-3 px-4">Payee</th>
                            <th class="py-3 px-4">Match</th>
                            <th class="py-3 px-4 text-right">Net Due</th>
                            <th class="py-3 px-4 text-right">Paid</th>
                            <th class="py-3 px-4 text-right">Outstanding</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4">Date</th>
                            <th class="py-3 px-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payouts as $payout)
                        <tr class="border-b border-stone-100 hover:bg-stone-50" x-data="{ open: false }">
                            <td class="py-3 px-4 font-mono text-xs text-stone-600">{{ $payout->reference }}</td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-stone-100 text-stone-600">
                                    {{ ucfirst(str_replace('_', ' ', $payout->payee_type)) }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-stone-700">{{ $payout->payeeUser?->name ?? 'SAPRF' }}</td>
                            <td class="py-3 px-4 text-stone-600">{{ $payout->match?->name ?? '—' }}</td>
                            <td class="py-3 px-4 text-right font-medium">R{{ number_format($payout->net_amount, 2) }}</td>
                            <td class="py-3 px-4 text-right text-emerald-700">R{{ number_format($payout->paid_amount, 2) }}</td>
                            <td class="py-3 px-4 text-right {{ $payout->outstandingBalance() > 0 ? 'text-red-600 font-semibold' : 'text-stone-400' }}">
                                R{{ number_format($payout->outstandingBalance(), 2) }}
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                    @if($payout->status === 'paid') bg-emerald-50 text-emerald-700
                                    @elseif($payout->status === 'partial') bg-amber-50 text-amber-700
                                    @else bg-stone-100 text-stone-600 @endif">
                                    {{ ucfirst($payout->status) }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-stone-500 text-xs">{{ $payout->created_at->format('d M Y') }}</td>
                            <td class="py-3 px-4 text-right">
                                @if(!$payout->isPaid())
                                <button @click="open = !open"
                                        class="text-emerald-700 hover:text-emerald-800 text-xs font-medium">
                                    Mark Paid
                                </button>
                                @endif
                            </td>
                        </tr>
                        @if(!$payout->isPaid())
                        <tr x-show="open" x-cloak class="bg-stone-50">
                            <td colspan="10" class="px-4 py-4">
                                <form method="POST" action="{{ route('financials.payouts.mark-paid', $payout) }}"
                                      class="flex flex-wrap items-end gap-4">
                                    @csrf
                                    <div>
                                        <label class="block text-xs font-medium text-stone-500 mb-1">Amount</label>
                                        <input type="number" name="paid_amount" step="0.01" min="0.01"
                                               value="{{ $payout->outstandingBalance() }}"
                                               class="rounded-lg border border-stone-300 text-sm py-2 px-3 w-36 focus:ring-emerald-500 focus:border-emerald-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-stone-500 mb-1">Reference</label>
                                        <input type="text" name="payment_reference" placeholder="EFT / bank ref"
                                               class="rounded-lg border border-stone-300 text-sm py-2 px-3 w-48 focus:ring-emerald-500 focus:border-emerald-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-stone-500 mb-1">Notes</label>
                                        <input type="text" name="notes" placeholder="Optional"
                                               class="rounded-lg border border-stone-300 text-sm py-2 px-3 w-48 focus:ring-emerald-500 focus:border-emerald-500">
                                    </div>
                                    <button type="submit"
                                            class="rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                                        Record Payment
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @endif
                        @empty
                        <tr>
                            <td colspan="10" class="py-0">
                                <x-empty-state class="!rounded-none !border-0 !border-t !border-dashed"
                                    heading="No payouts recorded yet"
                                    description="Once matches complete and payments settle, record Match Director payouts here. They're tracked against each match's net-to-MD amount."
                                    cta-label="Record Payout"
                                    :cta-href="route('financials.payouts.create')">
                                    <x-slot:icon>
                                        <svg class="h-6 w-6 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                                        </svg>
                                    </x-slot:icon>
                                </x-empty-state>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $payouts->withQueryString()->links() }}</div>
    </div>
</x-layouts.app>
