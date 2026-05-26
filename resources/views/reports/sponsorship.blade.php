<x-layouts.app :title="'Sponsorship Report - SAPRF'">
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <a href="{{ route('reports.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; All Reports</a>
                <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Sponsorship Report</h1>
                <p class="mt-1 text-sm text-stone-500">Sponsor revenue, payment history, and tier breakdown.</p>
            </div>
            <a href="{{ route('reports.sponsorship.export', request()->query()) }}"
               class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                Export CSV
            </a>
        </div>

        {{-- Filters --}}
        <form method="GET" class="rounded-xl border border-stone-200 bg-white shadow-sm p-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">Period</label>
                    <select name="period" onchange="this.form.submit()"
                            class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="" @selected(!request('period'))>All time</option>
                        <option value="this_year" @selected(request('period') === 'this_year')>This year</option>
                        <option value="last_year" @selected(request('period') === 'last_year')>Last year</option>
                        <option value="last_30" @selected(request('period') === 'last_30')>Last 30 days</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">From</label>
                    <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-500 mb-1.5">To</label>
                    <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <button type="submit" class="rounded-lg bg-stone-900 text-white text-sm font-semibold px-4 py-2 hover:bg-stone-800">Apply</button>
                @if(request()->query())
                    <a href="{{ route('reports.sponsorship') }}" class="text-sm text-stone-500 hover:text-stone-700">Clear</a>
                @endif
            </div>
        </form>

        {{-- KPI Cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                <p class="text-xs font-medium text-amber-700 uppercase">Total Sponsorship Revenue</p>
                <p class="mt-1 text-2xl font-bold text-amber-900">R{{ number_format($totalRevenue, 2) }}</p>
                <p class="mt-1 text-xs text-amber-600">{{ count($bySponsor) }} {{ Str::plural('sponsor', count($bySponsor)) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Linked to Sponsor Profile</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">R{{ number_format($totalLinkedRevenue, 2) }}</p>
                <p class="mt-1 text-xs text-stone-400">@if($totalRevenue > 0){{ number_format(($totalLinkedRevenue / $totalRevenue) * 100, 1) }}% of total @endif</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Active Sponsors</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">{{ $activeSponsors }}</p>
                @if($expiringSoon > 0)
                    <p class="mt-1 text-xs text-amber-700 font-medium">{{ $expiringSoon }} expiring within 30 days</p>
                @endif
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <p class="text-xs font-medium text-stone-500 uppercase">Unlinked Income</p>
                <p class="mt-1 text-2xl font-bold text-stone-900">R{{ number_format($totalUnlinkedRevenue, 2) }}</p>
                <p class="mt-1 text-xs text-stone-400">Not tied to a sponsor profile</p>
            </div>
        </div>

        {{-- By Sponsor --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="px-5 py-4 border-b border-stone-100">
                <h2 class="font-semibold text-stone-900">Revenue by Sponsor</h2>
                <p class="text-xs text-stone-500 mt-0.5">All sponsorship payments grouped by sponsor profile.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-stone-200 bg-stone-50">
                        <tr class="text-left text-xs uppercase text-stone-500">
                            <th class="px-5 py-3">Sponsor</th>
                            <th class="px-5 py-3">Tier</th>
                            <th class="px-5 py-3 text-right">Payments</th>
                            <th class="px-5 py-3">First Payment</th>
                            <th class="px-5 py-3">Latest Payment</th>
                            <th class="px-5 py-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse($bySponsor as $row)
                            <tr class="hover:bg-stone-50">
                                <td class="px-5 py-3.5 font-medium text-stone-900">
                                    {{ $row['sponsor_name'] }}
                                    @if(!$row['sponsor'])
                                        <span class="ml-2 inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-xs text-stone-500">Unlinked</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-stone-600">{{ $row['tier'] }}</td>
                                <td class="px-5 py-3.5 text-right font-mono text-stone-900">{{ $row['payment_count'] }}</td>
                                <td class="px-5 py-3.5 text-stone-500">{{ $row['first_payment']?->format('d M Y') ?? '—' }}</td>
                                <td class="px-5 py-3.5 text-stone-500">{{ $row['latest_payment']?->format('d M Y') ?? '—' }}</td>
                                <td class="px-5 py-3.5 text-right font-semibold text-emerald-700">R{{ number_format((float) $row['total'], 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-12 text-center text-sm text-stone-400">No sponsorship payments recorded for this period.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Sponsors without payments in this period --}}
        @if($sponsorsWithoutPayments->isNotEmpty())
            <div class="rounded-xl border border-amber-200 bg-amber-50/50 shadow-sm">
                <div class="px-5 py-4 border-b border-amber-200/60">
                    <h2 class="font-semibold text-stone-900">Active Sponsors Without Payments {{ $from || $to ? 'in this period' : 'recorded' }}</h2>
                    <p class="text-xs text-stone-500 mt-0.5">These sponsors are active in the system but have no income records linked to them. Consider recording their payment via Income.</p>
                </div>
                <div class="divide-y divide-amber-100">
                    @foreach($sponsorsWithoutPayments as $sponsor)
                        <div class="px-5 py-3 flex items-center justify-between">
                            <div>
                                <span class="font-medium text-stone-900">{{ $sponsor->name }}</span>
                                <span class="ml-2 text-xs text-stone-500">{{ $sponsor->tier?->name }}</span>
                            </div>
                            <a href="{{ route('financials.income.create') }}?category=sponsorship&sponsor_id={{ $sponsor->id }}" class="text-xs font-semibold text-emerald-700 hover:text-emerald-800">+ Record Payment</a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
