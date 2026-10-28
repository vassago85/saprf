<x-layouts.app :title="$match->name . ' — Finances'">
    @include('matches.workspace._chrome')

    @php
        $registeredShooters = (int) ($counts['counted'] ?? 0);
        $registeredRows = $match->registrations->where('registration_status', '!=', 'cancelled');
        $registeredFees = round((float) $registeredRows->sum('fee_amount'), 2);
        $registeredSaprf = round((float) $registeredRows->sum('saprf_fee'), 2);
        $registeredPlatform = round((float) $registeredRows->sum('platform_fee'), 2);
        $registeredGateway = round((float) $registeredRows->sum('gateway_fee'), 2);
        $registeredSurcharges = round((float) $registeredRows->sum('surcharge_amount'), 2);
        $registeredNet = round((float) $registeredRows->sum('md_net_amount'), 2);
        $actualMatchCosts = $viewerIsMatchDirector
            ? round((float) $expenses->sum(fn ($expense) => $expense->effectiveAmount($registeredShooters)), 2)
            : 0.0;
        $profitAfterCosts = round($registeredNet - $actualMatchCosts, 2);
    @endphp

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
                                    @if($uniformSurchargeRate !== null)
                                        Non-member surcharge ({{ $surchargedEntryCount }} × R{{ number_format($uniformSurchargeRate, fmod((float) $uniformSurchargeRate, 1.0) === 0.0 ? 0 : 2) }}, to SAPRF)
                                    @else
                                        Surcharges kept by SAPRF ({{ $surchargedEntryCount }} {{ \Illuminate\Support\Str::plural('entry', $surchargedEntryCount) }})
                                    @endif
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

        </div>

        {{-- Full expense editor (add / edit / delete). MD-only; replaces
             the read-only list that used to live inside the payout card
             and the dead "Add expense" link that pointed back at the
             settings tab. --}}
        @if($viewerIsMatchDirector)
            @include('matches.workspace._match-expenses')
        @endif

        @can('update', $match)
            @include('matches._cost-estimator', ['standalone' => true])
        @endcan

        {{-- Per-entry breakdown --}}
        @php
            $projectedEntries = collect($money['projected_entries'] ?? []);
            $projectedTotals = $money['projected_totals'] ?? null;
            $hasProjection = $projectedEntries->isNotEmpty();
        @endphp
        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white">
            <div class="border-b border-stone-100 px-5 py-3">
                <h3 class="text-sm font-semibold text-stone-900">Per-entry breakdown</h3>
                <p class="mt-0.5 text-xs text-stone-500">
                    Settled rows contribute to your payout today. Pending rows are shown as a
                    <span class="font-semibold text-amber-700">projection</span> — worst-case gateway fee applied,
                    on the assumption you'll chase the shooter and get paid eventually.
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
                            <td class="px-3 py-2 font-semibold" colspan="3">Total settled · {{ $counts['paid'] }} paid</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format((float) $money['gross_revenue'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format((float) $money['surcharges'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format((float) $money['saprf_fees'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format((float) $money['platform_fee_total'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format((float) $money['gateway_fees'], 2) }}</td>
                            <td class="px-3 py-2 text-right font-bold tabular-nums">{{ number_format((float) $money['md_net'], 2) }}</td>
                        </tr>
                        {{-- Projected pending rows: same shape as the paid rows
                             above, visually muted + flagged "projected" so an
                             MD doesn't mistake them for money already in the
                             bank. Fees come from MatchFinanceService's
                             worst-case back-fill so a zero-fee stub still
                             shows a realistic gateway cut. --}}
                        @foreach($projectedEntries as $reg)
                            <tr class="bg-amber-50/40">
                                <td class="px-3 py-2 text-stone-800">{{ $reg->shooter_name ?: $reg->user?->name }}</td>
                                <td class="px-3 py-2 text-stone-600">{{ $reg->feeCategoryLabel() }}</td>
                                <td class="px-3 py-2 text-stone-600">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-200">
                                        <flux:icon.clock class="size-3" />
                                        Projected · {{ $reg->payment_status }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-700">{{ number_format((float) $reg->fee_amount, 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">{{ number_format((float) $reg->surcharge_amount, 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">{{ number_format((float) $reg->saprf_fee, 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">{{ number_format((float) $reg->platform_fee, 2) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-stone-500">{{ number_format((float) $reg->gateway_fee, 2) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums text-stone-800">{{ number_format((float) $reg->md_net_amount, 2) }}</td>
                            </tr>
                        @endforeach
                        @if($hasProjection && $projectedTotals)
                            <tr class="bg-amber-100/60">
                                <td class="px-3 py-2 font-semibold text-stone-900" colspan="3">
                                    Projected total · {{ $projectedTotals['entry_count'] }} entries (incl. {{ $projectedEntries->count() }} pending)
                                </td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($projectedTotals['fee_amount'], 2) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($projectedTotals['surcharges'], 2) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($projectedTotals['saprf_fees'], 2) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($projectedTotals['platform_fees'], 2) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($projectedTotals['gateway_fees'], 2) }}</td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums text-amber-900">{{ number_format($projectedTotals['md_net'], 2) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
