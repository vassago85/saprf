<x-layouts.app :title="'Match Financials - ' . $match->name">
    <div class="space-y-6">
        <div>
            <a href="{{ route('financials.dashboard') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Back to Dashboard</a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">{{ $match->name }}</h1>
            <p class="mt-1 text-sm text-stone-500">
                {{ $match->match_date?->format('d M Y') }}
                &middot; {{ ucfirst($match->match_type ?? 'Match') }}
                @if($match->series_level) &middot; {{ ucfirst($match->series_level) }} @endif
                &middot; MD: {{ $match->user?->name ?? 'Unknown' }}
            </p>
        </div>

        {{-- Summary Cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Registrations</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">{{ $financials['total_registrations'] }}</p>
                <p class="mt-1 text-xs text-stone-400">{{ $financials['paid_registrations'] }} paid &middot; {{ $financials['pending_registrations'] }} pending</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Gross Revenue</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">R{{ number_format($financials['gross_revenue'], 2) }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 shadow-sm p-5">
                <p class="text-xs font-medium text-emerald-700 uppercase">MD Net Payout</p>
                <p class="mt-1 text-2xl font-bold text-emerald-800">R{{ number_format($financials['md_net'], 2) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Profit / Loss</p>
                <p class="mt-1 text-2xl font-bold {{ $financials['profit_loss'] >= 0 ? 'text-emerald-700' : 'text-red-600' }}">
                    R{{ number_format($financials['profit_loss'], 2) }}
                </p>
                <p class="mt-1 text-xs text-stone-400">After expenses</p>
            </div>
        </div>

        {{-- Entry Breakdown --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide mb-4">Entry Breakdown</h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-stone-600">Member Entries</dt>
                        <dd class="font-semibold text-stone-900">{{ $financials['member_entries'] }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-600">Lapsed Member Entries</dt>
                        <dd class="font-semibold text-amber-700">{{ $financials['lapsed_entries'] }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-600">Non-Member Entries</dt>
                        <dd class="font-semibold text-red-600">{{ $financials['non_member_entries'] }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide mb-4">Fee Breakdown</h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-stone-600">Gross Revenue</dt>
                        <dd class="font-semibold">R{{ number_format($financials['gross_revenue'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">SAPRF Fees</dt>
                        <dd class="text-red-600">-R{{ number_format($financials['saprf_fees'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Platform Fees</dt>
                        <dd class="text-red-600">-R{{ number_format($financials['platform_fees'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Gateway Fees</dt>
                        <dd class="text-red-600">-R{{ number_format($financials['gateway_fees'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Surcharges Collected</dt>
                        <dd class="text-emerald-600">+R{{ number_format($financials['surcharges'], 2) }}</dd>
                    </div>
                    @if($financials['refunds'] > 0)
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Refunds</dt>
                        <dd class="text-red-600">-R{{ number_format($financials['refunds'], 2) }}</dd>
                    </div>
                    @endif
                    <div class="flex justify-between border-t border-stone-200 pt-2">
                        <dt class="font-semibold text-stone-700">Net to MD</dt>
                        <dd class="font-bold text-stone-900">R{{ number_format($financials['md_net'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="font-semibold text-stone-700">Match Expenses</dt>
                        <dd class="font-bold text-red-600">-R{{ number_format($financials['total_expenses'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-stone-200 pt-2">
                        <dt class="font-semibold text-stone-700">Profit / Loss</dt>
                        <dd class="font-bold {{ $financials['profit_loss'] >= 0 ? 'text-emerald-700' : 'text-red-600' }}">
                            R{{ number_format($financials['profit_loss'], 2) }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- Match Expenses --}}
        @if($match->expenses->isNotEmpty())
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="p-6 border-b border-stone-100">
                <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide">Match Expenses</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs uppercase text-stone-500">
                            <th class="py-3 px-4">Description</th>
                            <th class="py-3 px-4">Type</th>
                            <th class="py-3 px-4 text-right">Unit Cost</th>
                            <th class="py-3 px-4 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $estimatedShooters = $match->estimated_shooters ?: ($match->max_competitors ?: 0); @endphp
                        @foreach($match->expenses as $expense)
                        <tr class="border-b border-stone-100">
                            <td class="py-3 px-4 text-stone-700">{{ $expense->description }}</td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                    {{ $expense->cost_type === 'per_shooter' ? 'bg-blue-50 text-blue-700' : 'bg-stone-100 text-stone-600' }}">
                                    {{ $expense->cost_type === 'per_shooter' ? 'Per Shooter' : 'Fixed' }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right text-stone-600">R{{ number_format($expense->amount, 2) }}</td>
                            <td class="py-3 px-4 text-right font-semibold text-stone-900">R{{ number_format($expense->effectiveAmount($estimatedShooters), 2) }}</td>
                        </tr>
                        @endforeach
                        <tr class="bg-stone-50">
                            <td colspan="3" class="py-3 px-4 font-semibold text-stone-700 text-right">Total Expenses</td>
                            <td class="py-3 px-4 text-right font-bold text-stone-900">R{{ number_format($financials['total_expenses'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- Registration Details --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="p-6 border-b border-stone-100">
                <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide">Registration Details</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs uppercase text-stone-500">
                            <th class="py-3 px-4">Shooter</th>
                            <th class="py-3 px-4">Category</th>
                            <th class="py-3 px-4">Payment</th>
                            <th class="py-3 px-4 text-right">Fee</th>
                            <th class="py-3 px-4 text-right">SAPRF</th>
                            <th class="py-3 px-4 text-right">Platform</th>
                            <th class="py-3 px-4 text-right">Gateway</th>
                            <th class="py-3 px-4 text-right">MD Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($match->registrations->where('registration_status', '!=', 'cancelled') as $reg)
                        <tr class="border-b border-stone-100">
                            <td class="py-2 px-4 text-stone-700">{{ $reg->shooter_name ?? $reg->user?->name ?? '—' }}</td>
                            <td class="py-2 px-4">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                    @if($reg->membership_fee_category === 'active_member') bg-emerald-50 text-emerald-700
                                    @elseif($reg->membership_fee_category === 'lapsed_member') bg-amber-50 text-amber-700
                                    @else bg-red-50 text-red-700 @endif">
                                    {{ $reg->feeCategoryLabel() }}
                                </span>
                            </td>
                            <td class="py-2 px-4">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $reg->payment_status === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                    {{ ucfirst($reg->payment_status ?? 'pending') }}
                                </span>
                            </td>
                            <td class="py-2 px-4 text-right">R{{ number_format($reg->fee_amount, 2) }}</td>
                            <td class="py-2 px-4 text-right text-stone-500">R{{ number_format($reg->saprf_fee, 2) }}</td>
                            <td class="py-2 px-4 text-right text-stone-500">R{{ number_format($reg->platform_fee, 2) }}</td>
                            <td class="py-2 px-4 text-right text-stone-500">R{{ number_format($reg->gateway_fee, 2) }}</td>
                            <td class="py-2 px-4 text-right font-semibold">R{{ number_format($reg->md_net_amount, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Action Bar --}}
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('matches.show', $match) }}"
               class="inline-flex items-center gap-2 rounded-lg bg-stone-100 px-5 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">
                View Full Match
            </a>
        </div>
    </div>
</x-layouts.app>
