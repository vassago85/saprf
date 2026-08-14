<x-layouts.app :title="'Financial Dashboard - SAPRF'">
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Financial Dashboard</h1>
                <p class="mt-1 text-sm text-stone-500">Platform-wide revenue, fees, and payout overview.</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('financials.export.dashboard-csv', request()->query()) }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-stone-100 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    CSV
                </a>
                <a href="{{ route('financials.export.dashboard-pdf', request()->query()) }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-stone-100 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    PDF
                </a>
                @role('developer')
                <a href="{{ route('financials.reset') }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                    Clear Data
                </a>
                @endrole
            </div>
        </div>

        {{-- Date Filters --}}
        <form method="GET" action="{{ route('financials.dashboard') }}" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Quick Filter</label>
                    <select name="period" onchange="this.form.submit()"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All Time</option>
                        <option value="month" @selected(request('period') === 'month')>This Month</option>
                        <option value="season" @selected(request('period') === 'season')>Season</option>
                    </select>
                </div>
                @if(request('period') === 'season')
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Season</label>
                    <select name="season_year" onchange="this.form.submit()"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        @foreach($seasons as $y)
                            <option value="{{ $y }}" @selected(request('season_year', now()->year) == $y)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">From</label>
                    <input type="date" name="from" value="{{ $from?->toDateString() }}"
                           class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">To</label>
                    <input type="date" name="to" value="{{ $to?->toDateString() }}"
                           class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <button type="submit"
                        class="rounded-lg bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Apply
                </button>
                @if(request()->hasAny(['from', 'to', 'period']))
                    <a href="{{ route('financials.dashboard') }}"
                       class="text-sm text-stone-500 hover:text-stone-700 underline py-2">Clear</a>
                @endif
            </div>
        </form>

        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs font-medium text-stone-500 uppercase tracking-wide">Gross Income</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">R{{ number_format($summary['gross_income'], 2) }}</p>
                <p class="mt-1 text-xs text-stone-400">All revenue sources</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 shadow-sm p-5">
                <p class="text-xs font-medium text-emerald-700 uppercase tracking-wide">Net Revenue</p>
                <p class="mt-1 text-2xl font-bold text-emerald-800">R{{ number_format($summary['net_revenue'], 2) }}</p>
                <p class="mt-1 text-xs text-emerald-600">Before expenses</p>
            </div>
            <div class="rounded-xl border border-red-200 bg-red-50 shadow-sm p-5">
                <p class="text-xs font-medium text-red-700 uppercase tracking-wide">Platform Expenses</p>
                <p class="mt-1 text-2xl font-bold text-red-800">R{{ number_format($summary['platform_expenses']['total'], 2) }}</p>
                <p class="mt-1 text-xs text-red-600">{{ $summary['platform_expenses']['count'] }} items</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 shadow-sm p-5">
                <p class="text-xs font-medium text-emerald-700 uppercase tracking-wide">Net After Expenses</p>
                <p class="mt-1 text-2xl font-bold {{ $summary['net_after_expenses'] >= 0 ? 'text-emerald-800' : 'text-red-700' }}">R{{ number_format($summary['net_after_expenses'], 2) }}</p>
                <p class="mt-1 text-xs text-emerald-600">Bottom line</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs font-medium text-stone-500 uppercase tracking-wide">MD Payouts</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">R{{ number_format($summary['total_md_payouts'], 2) }}</p>
                <p class="mt-1 text-xs text-stone-400">Due to match directors</p>
            </div>
        </div>

        {{-- Revenue Breakdown --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide mb-4">Match Revenue</h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-stone-600">Total Entries</dt>
                        <dd class="font-semibold text-stone-900">{{ number_format($summary['match_revenue']['total_entries']) }}</dd>
                    </div>
                    <div class="flex justify-between text-xs text-stone-400">
                        <dt>Members: {{ $summary['match_revenue']['member_entries'] }} | Lapsed: {{ $summary['match_revenue']['lapsed_entries'] }} | Non-member: {{ $summary['match_revenue']['non_member_entries'] }}</dt>
                    </div>
                    <div class="flex justify-between border-t border-stone-100 pt-2">
                        <dt class="text-stone-600">Gross Revenue</dt>
                        <dd class="font-semibold">R{{ number_format($summary['match_revenue']['gross'], 2) }}</dd>
                    </div>
                    @php
                        $platformFeeType = $settings['platform_fee_type'] ?? 'fixed';
                        $platformFeeValue = (float) ($settings['platform_fee_value'] ?? 0);
                        $platformFeeRateLabel = $platformFeeType === 'fixed'
                            ? 'R' . number_format($platformFeeValue, 2) . ' / shooter'
                            : rtrim(rtrim(number_format($platformFeeValue, 2), '0'), '.') . '%';
                    @endphp
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Platform Fees ({{ $platformFeeRateLabel }})</dt>
                        <dd class="text-red-600">-R{{ number_format($summary['match_revenue']['platform_fees'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">SAPRF Fees</dt>
                        <dd class="text-red-600">-R{{ number_format($summary['match_revenue']['saprf_fees'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Gateway Fees</dt>
                        <dd class="text-red-600">-R{{ number_format($summary['match_revenue']['gateway_fees'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Surcharges</dt>
                        <dd class="text-emerald-600">+R{{ number_format($summary['match_revenue']['surcharges'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-stone-200 pt-2">
                        <dt class="font-semibold text-stone-700">Net to MDs</dt>
                        <dd class="font-bold text-stone-900">R{{ number_format($summary['match_revenue']['md_net'], 2) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide mb-4">Membership Revenue</h2>
                <p class="text-xs text-stone-400 mb-3">SAPRF retains all membership fees</p>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-stone-600">Total Payments</dt>
                        <dd class="font-semibold text-stone-900">{{ number_format($summary['membership_revenue']['total_payments']) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-stone-100 pt-2">
                        <dt class="text-stone-600">Gross Revenue</dt>
                        <dd class="font-semibold">R{{ number_format($summary['membership_revenue']['gross'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Platform Cost ({{ $settings['membership_platform_fee_pct'] ?? '2.5' }}%)</dt>
                        <dd class="text-orange-600">-R{{ number_format($summary['membership_revenue']['platform_cost'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Est. Gateway Fees</dt>
                        <dd class="text-red-600">-R{{ number_format($summary['membership_revenue']['gateway_fees'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-stone-200 pt-2">
                        <dt class="font-semibold text-stone-700">Net to SAPRF</dt>
                        <dd class="font-bold text-emerald-700">R{{ number_format($summary['membership_revenue']['net_to_saprf'], 2) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- Other Income & Expenses --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide">Other Income</h2>
                    <a href="{{ route('financials.income') }}" class="text-xs text-emerald-700 hover:text-emerald-800 font-medium">Manage</a>
                </div>
                @if(!empty($summary['other_income']['by_category']))
                <dl class="space-y-2 text-sm">
                    @foreach($summary['other_income']['by_category'] as $cat => $amount)
                    <div class="flex justify-between">
                        <dt class="text-stone-600">{{ \App\Models\PlatformIncome::CATEGORIES[$cat] ?? ucfirst($cat) }}</dt>
                        <dd class="font-semibold text-emerald-700">R{{ number_format($amount, 2) }}</dd>
                    </div>
                    @endforeach
                    <div class="flex justify-between border-t border-stone-200 pt-2">
                        <dt class="font-semibold text-stone-700">Total</dt>
                        <dd class="font-bold text-emerald-800">R{{ number_format($summary['other_income']['total'], 2) }}</dd>
                    </div>
                </dl>
                @else
                <p class="text-sm text-stone-400">No other income recorded for this period.</p>
                @endif
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide">Platform Expenses</h2>
                    <a href="{{ route('financials.expenses') }}" class="text-xs text-emerald-700 hover:text-emerald-800 font-medium">Manage</a>
                </div>
                @if(!empty($summary['platform_expenses']['by_category']))
                <dl class="space-y-2 text-sm">
                    @foreach($summary['platform_expenses']['by_category'] as $cat => $amount)
                    <div class="flex justify-between">
                        <dt class="text-stone-600">{{ \App\Models\PlatformExpense::CATEGORIES[$cat] ?? ucfirst($cat) }}</dt>
                        <dd class="font-semibold text-red-600">R{{ number_format($amount, 2) }}</dd>
                    </div>
                    @endforeach
                    <div class="flex justify-between border-t border-stone-200 pt-2">
                        <dt class="font-semibold text-stone-700">Total</dt>
                        <dd class="font-bold text-red-700">R{{ number_format($summary['platform_expenses']['total'], 2) }}</dd>
                    </div>
                </dl>
                @else
                <p class="text-sm text-stone-400">No expenses recorded for this period.</p>
                @endif
            </div>
        </div>

        {{-- Monthly Trend --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide mb-4">Monthly Revenue Trend (12 months)</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs uppercase text-stone-500">
                            <th class="py-2 pr-4">Month</th>
                            <th class="py-2 pr-4 text-right">Match</th>
                            <th class="py-2 pr-4 text-right">Membership</th>
                            <th class="py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($monthlyTrend as $month)
                        <tr class="border-b border-stone-100">
                            <td class="py-2 pr-4 font-medium text-stone-700">{{ $month['label'] }}</td>
                            <td class="py-2 pr-4 text-right text-stone-600">R{{ number_format($month['match_revenue'], 2) }}</td>
                            <td class="py-2 pr-4 text-right text-stone-600">R{{ number_format($month['membership_revenue'], 2) }}</td>
                            <td class="py-2 text-right font-semibold text-stone-900">R{{ number_format($month['total'], 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Match Breakdown --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="p-6 border-b border-stone-100">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-stone-700 uppercase tracking-wide">Revenue by Match</h2>
                    <a href="{{ route('financials.export.matches-csv', request()->query()) }}"
                       class="text-xs text-emerald-700 hover:text-emerald-800 font-medium">Export CSV</a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-stone-200 text-left text-xs uppercase text-stone-500">
                            <th class="py-3 px-4">Match</th>
                            <th class="py-3 px-4">Date</th>
                            <th class="py-3 px-4 text-right">Entries</th>
                            <th class="py-3 px-4 text-right">Gross</th>
                            <th class="py-3 px-4 text-right">SAPRF Fees</th>
                            <th class="py-3 px-4 text-right">Platform</th>
                            <th class="py-3 px-4 text-right">Gateway</th>
                            <th class="py-3 px-4 text-right">MD Net</th>
                            <th class="py-3 px-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($matchBreakdown as $m)
                        <tr class="border-b border-stone-100 hover:bg-stone-50">
                            <td class="py-3 px-4 font-medium text-stone-800">{{ $m->name }}</td>
                            <td class="py-3 px-4 text-stone-500">{{ \Carbon\Carbon::parse($m->match_date)->format('d M Y') }}</td>
                            <td class="py-3 px-4 text-right text-stone-700">{{ $m->entries }}</td>
                            <td class="py-3 px-4 text-right font-medium">R{{ number_format($m->gross, 2) }}</td>
                            <td class="py-3 px-4 text-right text-red-600">R{{ number_format($m->saprf_fees, 2) }}</td>
                            <td class="py-3 px-4 text-right text-red-600">R{{ number_format($m->platform_fees, 2) }}</td>
                            <td class="py-3 px-4 text-right text-red-600">R{{ number_format($m->gateway_fees, 2) }}</td>
                            <td class="py-3 px-4 text-right font-semibold text-stone-900">R{{ number_format($m->md_net, 2) }}</td>
                            <td class="py-3 px-4 text-right">
                                <a href="{{ route('financials.match-report', $m->id) }}"
                                   class="text-emerald-700 hover:text-emerald-800 text-xs font-medium">View</a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="py-8 text-center text-sm text-stone-400">No match revenue data for this period.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
