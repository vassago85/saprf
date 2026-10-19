<x-layouts.app :title="'Payout ' . $payout->reference">
    <div class="space-y-6">
        <div>
            <a href="{{ route('my-payouts.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; All payouts</a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">{{ $payout->reference }}</h1>
            <p class="mt-1 text-sm text-stone-500">
                {{ $meta['name'] ?? ($payout->match?->name ?? 'Match') }}
                @if($meta['match_date'] ?? null) &middot; {{ \Carbon\Carbon::parse($meta['match_date'])->format('d M Y') }} @endif
                @if($meta['match_type'] ?? null) &middot; {{ ucfirst($meta['match_type']) }} @endif
                @if($meta['series_level'] ?? null) &middot; {{ ucfirst($meta['series_level']) }} @endif
                &middot; MD: {{ $meta['md_name'] ?? ($payout->payeeUser?->name ?? 'Unknown') }}
            </p>
            <p class="mt-1 text-xs text-stone-400">Statement v{{ $payout->statement_version }} &middot; generated {{ $payout->statement_generated_at?->format('d M Y H:i') }}</p>
        </div>

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('my-payouts.pdf', $payout) }}" class="rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Download PDF</a>
            <a href="{{ route('my-payouts.csv', $payout) }}" class="rounded-lg bg-emerald-50 px-5 py-2.5 text-sm font-semibold text-emerald-800 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100">Download CSV</a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide mb-4">Summary</h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-stone-600">Entries paid</dt>
                        <dd class="font-semibold text-stone-900">{{ $summary['paid_registrations'] ?? $paidItems->count() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-600">Gross revenue</dt>
                        <dd class="font-semibold">R{{ number_format((float) ($summary['gross_revenue'] ?? 0), 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Non-member surcharge (SAPRF)</dt>
                        <dd class="text-red-600">-R{{ number_format((float) ($summary['surcharges'] ?? 0), 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">SAPRF levy</dt>
                        <dd class="text-red-600">-R{{ number_format((float) ($summary['saprf_fees'] ?? 0), 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Platform fee</dt>
                        <dd class="text-red-600">-R{{ number_format((float) ($summary['platform_fees'] ?? 0), 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Gateway fees</dt>
                        <dd class="text-red-600">-R{{ number_format((float) ($summary['gateway_fees'] ?? 0), 2) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-stone-200 pt-2">
                        <dt class="font-semibold text-stone-700">Net due to MD</dt>
                        <dd class="font-bold text-emerald-700">R{{ number_format((float) $payout->net_amount, 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-600">Paid to date</dt>
                        <dd class="font-semibold text-emerald-700">R{{ number_format((float) $payout->paid_amount, 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-600">Outstanding</dt>
                        <dd class="font-semibold {{ $payout->outstandingBalance() > 0 ? 'text-red-600' : 'text-stone-400' }}">
                            R{{ number_format($payout->outstandingBalance(), 2) }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide mb-4">Payment history</h2>
                @if($payments->isNotEmpty())
                    <ul class="space-y-3 text-sm">
                        @foreach($payments as $tx)
                            <li class="flex justify-between gap-4 border-b border-stone-100 pb-2 last:border-0">
                                <div>
                                    <div class="text-stone-800">{{ $tx->created_at->format('d M Y') }}</div>
                                    <div class="text-xs text-stone-500">Ref: {{ data_get($tx, 'meta.payment_reference') ?? ($payout->payment_reference ?? '—') }}</div>
                                </div>
                                <div class="text-right font-semibold text-emerald-700">R{{ number_format((float) $tx->amount, 2) }}</div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-stone-500">No payments against this payout yet.</p>
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="p-6 border-b border-stone-100">
                <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide">Paid entries</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs uppercase text-stone-500">
                            <th class="py-3 px-4">Shooter</th>
                            <th class="py-3 px-4">Category</th>
                            <th class="py-3 px-4">Method</th>
                            <th class="py-3 px-4 text-right">Fee</th>
                            <th class="py-3 px-4 text-right">Surcharge</th>
                            <th class="py-3 px-4 text-right">SAPRF</th>
                            <th class="py-3 px-4 text-right">Platform</th>
                            <th class="py-3 px-4 text-right">Gateway</th>
                            <th class="py-3 px-4 text-right">MD Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($paidItems as $item)
                            <tr class="border-b border-stone-100">
                                <td class="py-2 px-4 text-stone-700">{{ $item->shooter_name }}</td>
                                <td class="py-2 px-4 text-stone-600">{{ ucfirst(str_replace('_', ' ', (string) $item->membership_fee_category)) }}</td>
                                <td class="py-2 px-4 text-stone-600">{{ $item->methodLabel() }}</td>
                                <td class="py-2 px-4 text-right">R{{ number_format((float) $item->gross_amount, 2) }}</td>
                                <td class="py-2 px-4 text-right text-stone-500">R{{ number_format((float) $item->surcharge_amount, 2) }}</td>
                                <td class="py-2 px-4 text-right text-stone-500">R{{ number_format((float) $item->saprf_fee, 2) }}</td>
                                <td class="py-2 px-4 text-right text-stone-500">R{{ number_format((float) $item->platform_fee, 2) }}</td>
                                <td class="py-2 px-4 text-right text-stone-500">R{{ number_format((float) $item->gateway_fee, 2) }}</td>
                                <td class="py-2 px-4 text-right font-semibold">R{{ number_format((float) $item->net_amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-6 px-4 text-center text-sm text-stone-500">No paid entries on this statement.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($paidItems->isNotEmpty())
                        <tfoot>
                            <tr class="bg-stone-50 font-semibold text-stone-800">
                                <td class="py-3 px-4" colspan="3">Totals ({{ $paidItems->count() }} paid)</td>
                                <td class="py-3 px-4 text-right">R{{ number_format((float) ($summary['gross_revenue'] ?? 0), 2) }}</td>
                                <td class="py-3 px-4 text-right">R{{ number_format((float) ($summary['surcharges'] ?? 0), 2) }}</td>
                                <td class="py-3 px-4 text-right">R{{ number_format((float) ($summary['saprf_fees'] ?? 0), 2) }}</td>
                                <td class="py-3 px-4 text-right">R{{ number_format((float) ($summary['platform_fees'] ?? 0), 2) }}</td>
                                <td class="py-3 px-4 text-right">R{{ number_format((float) ($summary['gateway_fees'] ?? 0), 2) }}</td>
                                <td class="py-3 px-4 text-right">R{{ number_format((float) ($summary['md_net'] ?? $payout->net_amount), 2) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        @if($excludedItems->isNotEmpty())
            <div class="rounded-xl border border-amber-200 bg-amber-50/40 shadow-sm">
                <div class="p-6 border-b border-amber-200">
                    <h2 class="text-sm font-semibold text-amber-800 uppercase tracking-wide">Not included in this payout</h2>
                </div>
                <ul class="divide-y divide-amber-100">
                    @foreach($excludedItems as $item)
                        <li class="flex justify-between items-center px-6 py-3 text-sm">
                            <span class="text-stone-700">{{ $item->shooter_name }}</span>
                            <span class="text-xs text-amber-800">{{ ucfirst((string) ($item->excluded_reason ?: $item->payment_status)) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-layouts.app>
