<x-layouts.app>
    <x-slot:title>My Dashboard - SAPRF</x-slot:title>

    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-stone-500">Welcome back,</p>
                <h1 class="font-heading text-3xl font-bold text-stone-900">{{ Str::before($user->name, ' ') }}</h1>
            </div>
            @if($membership && $membership->membership_type === 'paid')
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold bg-emerald-100 text-emerald-800 ring-1 ring-inset ring-emerald-600/20">SAPRF Member</span>
            @else
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold bg-stone-100 text-stone-600 ring-1 ring-inset ring-stone-500/20">Free Account</span>
            @endif
        </div>

        {{-- Membership Status Card --}}
        @if($membership && $membership->membership_type === 'paid')
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-heading text-xl font-bold text-stone-900">Membership</h2>
                    @if($membership->status === 'active')
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Active</span>
                    @elseif($membership->status === 'pending')
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">Pending</span>
                    @else
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800">{{ ucfirst($membership->status) }}</span>
                    @endif
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                        <p class="text-xs text-stone-500 uppercase tracking-wider">SAPRF Number</p>
                        <p class="text-lg font-bold text-stone-900 mt-1 font-mono">{{ $membership->saprf_number ?? '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                        <p class="text-xs text-stone-500 uppercase tracking-wider">Province</p>
                        <p class="text-lg font-bold text-stone-900 mt-1">{{ $user->province?->name ?? '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                        <p class="text-xs text-stone-500 uppercase tracking-wider">Status</p>
                        <p class="text-lg font-bold text-stone-900 mt-1">{{ ucfirst($membership->status) }}</p>
                    </div>
                    <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                        <p class="text-xs text-stone-500 uppercase tracking-wider">Expires</p>
                        <p class="text-lg font-bold text-stone-900 mt-1">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</p>
                    </div>
                </div>
            </div>
        @elseif($membership && $membership->status === 'pending' && $membership->payment_status !== 'paid')
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-start gap-4">
                        <div class="inline-flex items-center justify-center size-10 rounded-lg bg-amber-100 text-amber-700 shrink-0">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </div>
                        <div>
                            <h3 class="font-heading text-lg font-bold text-amber-900">Payment Pending</h3>
                            <p class="text-sm text-amber-800 mt-1">Your membership is ready — complete payment to activate.</p>
                        </div>
                    </div>
                    <a href="{{ route('my-membership') }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 shrink-0">Complete Payment</a>
                </div>
            </div>
        @else
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-start gap-4">
                        <div class="inline-flex items-center justify-center size-10 rounded-lg bg-stone-100 text-stone-500 shrink-0">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z" /></svg>
                        </div>
                        <div>
                            <h3 class="font-heading text-lg font-bold text-stone-900">Free Account</h3>
                            <p class="text-sm text-stone-600 mt-1">You can register for matches but won't appear in official standings. Become a member for a SAPRF number, standings eligibility, and reduced match fees.</p>
                        </div>
                    </div>
                    <a href="{{ route('my-membership') }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 shrink-0">Become a Member</a>
                </div>
            </div>
        @endif

        {{-- Season Stats --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs text-stone-500 uppercase tracking-wider">Matches Shot</p>
                <p class="text-3xl font-bold text-stone-900 mt-2 font-mono">{{ $matchesShot }}</p>
                <p class="text-xs text-stone-400 mt-1">this season</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs text-stone-500 uppercase tracking-wider">Best Placement</p>
                @if($bestPlacement)
                    <p class="text-3xl font-bold text-amber-600 mt-2 font-mono">#{{ $bestPlacement }}</p>
                @else
                    <p class="text-sm text-stone-400 mt-3">No results yet</p>
                @endif
            </div>
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs text-stone-500 uppercase tracking-wider">Avg Placement</p>
                @if($avgPlacement)
                    <p class="text-3xl font-bold text-stone-900 mt-2 font-mono">#{{ $avgPlacement }}</p>
                @else
                    <p class="text-sm text-stone-400 mt-3">No results yet</p>
                @endif
            </div>
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-5">
                <p class="text-xs text-stone-500 uppercase tracking-wider">Points Earned</p>
                <p class="text-3xl font-bold text-emerald-700 mt-2 font-mono">{{ number_format($totalPoints) }}</p>
                <p class="text-xs text-stone-400 mt-1">national standings</p>
            </div>
        </div>

        {{-- My Rifles --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-heading text-xl font-bold text-stone-900">My Rifles</h2>
                <div class="flex items-center gap-3">
                    @if($rifleCount > 3)
                        <a href="{{ route('rifle-configurations.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">View All ({{ $rifleCount }})</a>
                    @endif
                    <a href="{{ route('rifle-configurations.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Add Rifle
                    </a>
                </div>
            </div>

            @if($rifles->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    @foreach($rifles as $rifle)
                        <a href="{{ route('rifle-configurations.show', $rifle) }}" class="block rounded-lg border border-stone-200 p-4 hover:border-emerald-300 hover:shadow-sm transition group">
                            <div class="flex items-start justify-between mb-2">
                                <h3 class="font-semibold text-stone-900 group-hover:text-emerald-700">{{ $rifle->nickname }}</h3>
                                @if($rifle->is_primary)
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold bg-emerald-100 text-emerald-700">Primary</span>
                                @endif
                            </div>
                            @if($rifle->make || $rifle->model)
                                <p class="text-sm text-stone-600">{{ $rifle->make?->name }} {{ $rifle->model?->name }}</p>
                            @endif
                            @if($rifle->calibre)
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold bg-stone-100 text-stone-600 mt-2">{{ $rifle->calibre->name }}</span>
                            @endif
                            <p class="text-xs text-stone-400 mt-2">{{ $rifle->registrations_count }} {{ Str::plural('match', $rifle->registrations_count) }}</p>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <p class="text-sm text-stone-400">No rifle configurations yet. Add your first rifle to start tracking performance.</p>
                </div>
            @endif
        </div>

        {{-- Recent Match History --}}
        @if($recentMatches->isNotEmpty())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-stone-200">
                    <h2 class="font-heading text-xl font-bold text-stone-900">Recent Match History</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-stone-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Date</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Match</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Province</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Placement</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-stone-500">Impacts</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($recentMatches as $score)
                                <tr class="hover:bg-stone-50">
                                    <td class="px-5 py-3 text-stone-600 whitespace-nowrap">{{ $score->match?->match_date?->format('d M Y') }}</td>
                                    <td class="px-5 py-3 font-medium text-stone-900">{{ $score->match?->name ?? '—' }}</td>
                                    <td class="px-5 py-3 text-stone-600">{{ $score->match?->province?->abbreviation ?? '—' }}</td>
                                    <td class="px-5 py-3 text-right font-mono font-bold {{ $score->placement && $score->placement <= 3 ? 'text-amber-600' : 'text-stone-900' }}">
                                        {{ $score->placement ? '#'.$score->placement : '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-right font-mono text-stone-700">{{ $score->raw_score ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Qualification Progress --}}
        @if($qualificationProgress ?? null)
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4">
                <h2 class="font-heading text-xl font-bold text-stone-900">Qualification Progress</h2>
                <p class="text-sm text-stone-500">Out-of-province national matches required to qualify for finals.</p>
                <div class="space-y-3">
                    @foreach($qualificationProgress as $series => $progress)
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-semibold text-stone-900">{{ $series }}</span>
                                <span class="text-xs font-medium text-stone-500">
                                    {{ $progress['completed'] }}/{{ $progress['required'] }} matches
                                    @if($progress['completed'] >= $progress['required'])
                                        <span class="text-emerald-600 ml-1">Qualified</span>
                                    @endif
                                </span>
                            </div>
                            <div class="h-2.5 bg-stone-200 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all {{ $progress['completed'] >= $progress['required'] ? 'bg-emerald-500' : 'bg-emerald-600' }}"
                                    style="width: {{ min(100, ($progress['required'] > 0 ? ($progress['completed'] / $progress['required']) * 100 : 0)) }}%"
                                ></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Next Match --}}
        @if($nextMatch ?? null)
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-emerald-600 uppercase tracking-wider font-semibold">Next Match</p>
                        <h3 class="font-heading text-xl font-bold text-emerald-900 mt-1">{{ $nextMatch->name }}</h3>
                        <p class="text-sm text-emerald-700 mt-1">{{ $nextMatch->match_date->format('d M Y') }} &middot; {{ $nextMatch->province?->name ?? 'TBA' }}</p>
                    </div>
                    <a href="{{ route('registrations.store') }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Register</a>
                </div>
            </div>
        @endif
    </div>

    <x-dev-switcher />
</x-layouts.app>
