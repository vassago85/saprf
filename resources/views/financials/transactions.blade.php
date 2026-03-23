<x-layouts.app :title="'Financial Transactions - SAPRF'">
    <div class="space-y-6">
        <div>
            <a href="{{ route('financials.dashboard') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Dashboard</a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">Financial Transactions</h1>
            <p class="mt-1 text-sm text-stone-500">Audit trail of all financial actions: payments, refunds, adjustments, payouts.</p>
        </div>

        {{-- Filters --}}
        <div class="flex gap-2">
            <a href="{{ route('financials.transactions') }}"
               class="rounded-lg px-3 py-1.5 text-sm font-medium {{ !$type ? 'bg-stone-800 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }} transition">
                All
            </a>
            @foreach(\App\Models\FinancialTransaction::TYPES as $key => $label)
            <a href="{{ route('financials.transactions', ['type' => $key]) }}"
               class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $type === $key ? 'bg-stone-800 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }} transition">
                {{ $label }}
            </a>
            @endforeach
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs uppercase text-stone-500">
                            <th class="py-3 px-4">Date</th>
                            <th class="py-3 px-4">Type</th>
                            <th class="py-3 px-4">Description</th>
                            <th class="py-3 px-4 text-right">Amount</th>
                            <th class="py-3 px-4">User</th>
                            <th class="py-3 px-4">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions as $txn)
                        <tr class="border-b border-stone-100 hover:bg-stone-50">
                            <td class="py-3 px-4 text-stone-500 text-xs whitespace-nowrap">{{ $txn->created_at->format('d M Y H:i') }}</td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                    @if($txn->type === 'payment') bg-emerald-50 text-emerald-700
                                    @elseif($txn->type === 'refund') bg-red-50 text-red-700
                                    @elseif($txn->type === 'payout') bg-blue-50 text-blue-700
                                    @else bg-amber-50 text-amber-700 @endif">
                                    {{ ucfirst($txn->type) }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-stone-700">{{ $txn->description }}</td>
                            <td class="py-3 px-4 text-right font-semibold {{ $txn->type === 'refund' ? 'text-red-600' : 'text-stone-900' }}">
                                R{{ number_format(abs($txn->amount), 2) }}
                            </td>
                            <td class="py-3 px-4 text-stone-600">{{ $txn->user?->name ?? '—' }}</td>
                            <td class="py-3 px-4 text-xs text-stone-400">{{ $txn->source_type }}:{{ $txn->source_id }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-sm text-stone-400">No transactions recorded yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $transactions->withQueryString()->links() }}</div>
    </div>
</x-layouts.app>
