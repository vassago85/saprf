<x-layouts.app :title="$match->name . ' — Finances'">
    @include('matches.workspace._chrome')

    <div class="mt-6 space-y-4">
        <div class="grid gap-4 lg:grid-cols-3">
            {{-- Payout ledger --}}
            <div class="rounded-xl border border-stone-200 bg-white p-5 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-stone-900">
                        @if($payout)
                            Payout statement · {{ $payout->reference }}
                        @else
                            Payout projection
                        @endif
                    </h3>
                    @if($payout)
                        @php
                            $payoutBadge = match ($payout->status) {
                                'paid' => ['Paid', 'bg-emerald-100 text-emerald-800'],
                                'partial' => ['Partial', 'bg-sky-100 text-sky-800'],
                                default => ['Pending', 'bg-amber-100 text-amber-800'],
                            };
                        @endphp
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $payoutBadge[1] }}">
                            {{ $payoutBadge[0] }}
                        </span>
                    @else
                        <span class="inline-flex rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600">
                            Not yet requested
                        </span>
                    @endif
                </div>

                <table class="mt-4 w-full text-sm">
                    <tbody>
                        <tr>
                            <td class="py-1.5 text-stone-700">Collected from {{ $counts['paid'] }} paid entries</td>
                            <td class="py-1.5 text-right tabular-nums text-stone-900">R {{ number_format((float) $money['gross_revenue'], 2) }}</td>
                        </tr>
                        @if($money['surcharges'] > 0)
                            <tr>
                                <td class="py-1.5 text-stone-700">
                                    Non-member surcharge ({{ $surchargedEntryCount }} × R{{ number_format($surchargePerShooter, 0) }}, to SAPRF)
                                </td>
                                <td class="py-1.5 text-right tabular-nums text-stone-500">
                                    − R {{ number_format((float) $money['surcharges'], 2) }}
                                </td>
                            </tr>
                        @endif
                        <tr>
                            <td class="py-1.5 text-stone-700">
                                SAPRF levy ({{ $counts['paid'] }} entries)
                            </td>
                            <td class="py-1.5 text-right tabular-nums text-stone-500">
                                − R {{ number_format((float) $money['saprf_fees'], 2) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="py-1.5 text-stone-700">
                                Platform fee ({{ $counts['paid'] }} entries)
                            </td>
                            <td class="py-1.5 text-right tabular-nums text-stone-500">
                                − R {{ number_format((float) $money['platform_fee_total'], 2) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="py-1.5 text-stone-700">Gateway fees (PayFast, actual)</td>
                            <td class="py-1.5 text-right tabular-nums text-stone-500">− R {{ number_format((float) $money['gateway_fees'], 2) }}</td>
                        </tr>
                        <tr class="border-t-2 border-stone-900">
                            <td class="pt-3 text-base font-semibold text-stone-900">Net payout to you</td>
                            <td class="pt-3 text-right text-base font-bold tabular-nums text-stone-900">R {{ number_format((float) $money['md_net'], 2) }}</td>
                        </tr>
                        @if($payout)
                            <tr>
                                <td class="py-1.5 text-xs text-stone-500">Paid to date</td>
                                <td class="py-1.5 text-right text-xs tabular-nums text-stone-500">R {{ number_format((float) $payout->paid_amount, 2) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>

                @if($payout)
                    <div class="mt-4 flex gap-2">
                        <a href="{{ route('my-payouts.pdf', $payout) }}"
                           class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">
                            <flux:icon.document-arrow-down class="size-4" />
                            Download PDF
                        </a>
                        <a href="{{ route('my-payouts.csv', $payout) }}"
                           class="inline-flex items-center gap-1.5 rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-semibold text-stone-700 hover:bg-stone-50">
                            <flux:icon.table-cells class="size-4" />
                            Download CSV
                        </a>
                    </div>
                @endif

                @if($money['md_payout_projection'] > $money['md_net'])
                    <p class="mt-3 text-xs text-stone-500">
                        Projected if every outstanding entry pays:
                        <span class="font-semibold text-stone-700 tabular-nums">R {{ number_format((float) $money['md_payout_projection'], 2) }}</span>
                    </p>
                @endif
            </div>

            {{-- Expenses + P&L (MD only) --}}
            @if($viewerIsMatchDirector)
                <div class="rounded-xl border border-stone-200 bg-white p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-stone-900">Match expenses</h3>
                        <span class="inline-flex rounded-full bg-stone-100 px-2 py-0.5 text-xs font-semibold text-stone-600">
                            Only you see this
                        </span>
                    </div>
                    @if($expenses->isEmpty())
                        <div class="mt-4 rounded-lg border border-dashed border-stone-200 px-4 py-6 text-center text-sm text-stone-500">
                            No expenses recorded yet.
                            <div class="mt-3">
                                <a href="{{ route('matches.settings', $match) }}#expenses" wire:navigate
                                   class="inline-flex items-center rounded-md border border-stone-300 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
                                    Add expense
                                </a>
                            </div>
                        </div>
                    @else
                        <ul class="mt-3 divide-y divide-stone-100 text-sm">
                            @foreach($expenses as $exp)
                                <li class="flex items-center justify-between gap-3 py-2">
                                    <span class="min-w-0 flex-1 truncate text-stone-700">{{ $exp->description }}</span>
                                    <span class="shrink-0 tabular-nums text-stone-900">R {{ number_format($exp->effectiveAmount($counts['paid']), 2) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <table class="mt-4 w-full text-sm">
                        <tbody>
                            <tr class="border-t-2 border-stone-900">
                                <td class="pt-3 text-base font-semibold text-stone-900">Estimated profit</td>
                                <td class="pt-3 text-right text-base font-bold tabular-nums text-stone-900">
                                    R {{ number_format((float) $money['profit_loss'], 2) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Per-entry breakdown --}}
        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
            <div class="border-b border-stone-100 px-5 py-3">
                <h3 class="text-sm font-semibold text-stone-900">Per-entry breakdown</h3>
                <p class="mt-0.5 text-xs text-stone-500">
                    Only paid entries contribute to the payout. Pending rows are listed for reference and marked “not included”.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">Shooter</th>
                            <th class="px-3 py-2 text-left font-semibold">Category</th>
                            <th class="px-3 py-2 text-left font-semibold">Method</th>
                            <th class="px-3 py-2 text-right font-semibold">Fee</th>
                            <th class="px-3 py-2 text-right font-semibold">Surcharge</th>
                            <th class="px-3 py-2 text-right font-semibold">SAPRF</th>
                            <th class="px-3 py-2 text-right font-semibold">Platform</th>
                            <th class="px-3 py-2 text-right font-semibold">Gateway</th>
                            <th class="px-3 py-2 text-right font-semibold">Your net</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($money['paid_entries'] as $reg)
                            <tr>
                                <td class="px-3 py-2 text-stone-900">{{ $reg->shooter_name ?: $reg->user?->name }}</td>
                                <td class="px-3 py-2 text-stone-600">{{ $reg->feeCategoryLabel() }}</td>
                                <td class="px-3 py-2 text-stone-600">{{ $reg->paymentMethodLabel() }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format((float) $reg->fee_amount, 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">{{ number_format((float) $reg->surcharge_amount, 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">{{ number_format((float) $reg->saprf_fee, 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">{{ number_format((float) $reg->platform_fee, 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">{{ number_format((float) $reg->gateway_fee, 2) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums text-stone-900">{{ number_format((float) $reg->md_net_amount, 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="bg-stone-50">
                            <td class="px-3 py-2 font-semibold" colspan="3">Total · {{ $counts['paid'] }} paid</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format((float) $money['gross_revenue'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format((float) $money['surcharges'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format((float) $money['saprf_fees'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format((float) $money['platform_fee_total'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format((float) $money['gateway_fees'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-bold tabular-nums">{{ number_format((float) $money['md_net'], 2) }}</td>
                        </tr>
                        @foreach($money['excluded_entries'] as $reg)
                            <tr class="text-stone-500">
                                <td class="px-3 py-2">{{ $reg->shooter_name ?: $reg->user?->name }}</td>
                                <td class="px-3 py-2">{{ $reg->feeCategoryLabel() }}</td>
                                <td class="px-3 py-2">{{ $reg->paymentMethodLabel() }}</td>
                                <td class="px-3 py-2 text-right italic" colspan="6">Not included · {{ $reg->payment_status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
