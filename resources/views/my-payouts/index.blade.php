<x-layouts.app title="My payouts">
    <div class="space-y-6">
        <div>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">My payouts</h1>
            <p class="mt-1 text-sm text-stone-500">
                @if($isFinance)
                    Every MD payout the federation has raised.
                @else
                    Payouts for matches you directed. Each one links to the frozen statement and download.
                @endif
            </p>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs uppercase text-stone-500">
                            <th class="py-3 px-4">Reference</th>
                            @if($isFinance)
                                <th class="py-3 px-4">MD</th>
                            @endif
                            <th class="py-3 px-4">Match</th>
                            <th class="py-3 px-4 text-right">Net due</th>
                            <th class="py-3 px-4 text-right">Paid</th>
                            <th class="py-3 px-4 text-right">Outstanding</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4 text-right"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payouts as $payout)
                            <tr class="border-b border-stone-100 hover:bg-stone-50">
                                <td class="py-3 px-4 font-mono text-xs text-stone-800">{{ $payout->reference }}</td>
                                @if($isFinance)
                                    <td class="py-3 px-4 text-stone-700">{{ $payout->payeeUser?->name ?? '—' }}</td>
                                @endif
                                <td class="py-3 px-4 text-stone-700">
                                    {{ $payout->match?->name ?? '—' }}
                                    <span class="block text-xs text-stone-400">{{ $payout->match?->match_date?->format('d M Y') }}</span>
                                </td>
                                <td class="py-3 px-4 text-right font-semibold">R{{ number_format((float) $payout->net_amount, 2) }}</td>
                                <td class="py-3 px-4 text-right text-emerald-700">R{{ number_format((float) $payout->paid_amount, 2) }}</td>
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
                                <td class="py-3 px-4 text-right">
                                    <a href="{{ route('my-payouts.show', $payout) }}" class="text-emerald-700 hover:text-emerald-800 text-xs font-semibold">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isFinance ? 8 : 7 }}" class="py-10 px-4 text-center text-sm text-stone-500">
                                    No payouts to show yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div>{{ $payouts->links() }}</div>
    </div>
</x-layouts.app>
