<x-layouts.app>
    <x-slot:title>Admin Dashboard - SAPRF</x-slot:title>

    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-stone-500">Welcome back, {{ Str::before($user->name, ' ') }}</p>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Admin Dashboard</h1>
            </div>
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Admin</span>
        </div>

        <hr class="border-stone-200 my-6">

        <!-- Status Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-stone-500">Pending Memberships</p>
                    @if(($pendingMemberships ?? 0) > 0)
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">{{ $pendingMemberships }}</span>
                    @endif
                </div>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($pendingMemberships ?? 0) }}</p>
                <a href="{{ route('memberships.index', ['status' => 'pending']) }}" class="inline-flex items-center justify-center w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100 transition-colors">
                    Review Pending
                </a>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <p class="text-sm text-stone-500">Upcoming Matches</p>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($upcomingMatches ?? 0) }}</p>
                <a href="{{ route('matches.index') }}" class="inline-flex items-center justify-center w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100 transition-colors">
                    View Matches
                </a>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-stone-500">Pending Scores</p>
                    @if(($pendingScores ?? 0) > 0)
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">{{ $pendingScores }}</span>
                    @endif
                </div>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($pendingScores ?? 0) }}</p>
                <a href="{{ route('score-imports.index') }}" class="inline-flex items-center justify-center w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100 transition-colors">
                    Review Scores
                </a>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-stone-500">Pending Approvals</p>
                    @if(($pendingApprovals ?? 0) > 0)
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">{{ $pendingApprovals }}</span>
                    @endif
                </div>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($pendingApprovals ?? 0) }}</p>
                <a href="{{ route('approvals.index') }}" class="inline-flex items-center justify-center w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100 transition-colors">
                    Review Approvals
                </a>
            </div>

            <div class="rounded-xl border {{ ($pendingMdPayouts ?? 0) > 0 ? 'border-amber-200 bg-amber-50' : 'border-stone-200 bg-white' }} shadow-sm p-6 space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-sm {{ ($pendingMdPayouts ?? 0) > 0 ? 'text-amber-800' : 'text-stone-500' }}">Pending MD Payouts</p>
                    @if(($pendingMdPayouts ?? 0) > 0)
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-900">{{ $pendingMdPayouts }}</span>
                    @endif
                </div>
                <p class="text-3xl font-bold {{ ($pendingMdPayouts ?? 0) > 0 ? 'text-amber-900' : 'text-stone-900' }}">
                    {{ number_format($pendingMdPayouts ?? 0) }}
                </p>
                @if(($pendingMdPayouts ?? 0) > 0)
                    <p class="text-xs {{ ($pendingMdPayouts ?? 0) > 0 ? 'text-amber-700' : 'text-stone-400' }}">
                        R{{ number_format($pendingMdPayoutsTotal ?? 0, 2) }} outstanding
                    </p>
                @endif
                <a href="{{ route('financials.payouts', ['type' => 'match_director', 'status' => 'pending']) }}"
                   class="inline-flex items-center justify-center w-full rounded-lg border {{ ($pendingMdPayouts ?? 0) > 0 ? 'border-amber-300 bg-amber-100 text-amber-900 hover:bg-amber-200' : 'border-stone-200 bg-stone-50 text-stone-700 hover:bg-stone-100' }} px-3 py-1.5 text-sm font-medium transition-colors">
                    Review Payout Requests
                </a>
            </div>
        </div>

        <hr class="border-stone-200 my-6">

        <!-- Recent Audit Logs -->
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="font-heading text-xl font-bold text-stone-900">Recent Audit Logs</h2>
                <a href="{{ route('audit-logs.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800 transition-colors">
                    View All &rarr;
                </a>
            </div>

            @if(isset($recentAuditLogs) && $recentAuditLogs->count())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-stone-200">
                                <th class="pb-2 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">Action</th>
                                <th class="pb-2 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">User</th>
                                <th class="pb-2 text-left text-xs font-semibold text-stone-500 uppercase tracking-wider">Entity</th>
                                <th class="pb-2 text-right text-xs font-semibold text-stone-500 uppercase tracking-wider">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($recentAuditLogs as $log)
                                <tr>
                                    <td class="py-3 font-medium text-stone-900">{{ $log->action_type }}</td>
                                    <td class="py-3 text-stone-500">{{ $log->user?->name ?? 'System' }}</td>
                                    <td class="py-3 text-stone-500">{{ $log->entity_type }}</td>
                                    <td class="py-3 text-right text-stone-400">{{ $log->created_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-stone-500">No recent audit logs.</p>
            @endif
        </div>

        <hr class="border-stone-200 my-6">

        <!-- Quick Links -->
        <div>
            <h2 class="font-heading text-xl font-bold text-stone-900 mb-4">Quick Links</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <flux:button href="{{ route('memberships.index') }}" variant="filled" class="justify-start" icon="users">
                    Memberships
                </flux:button>
                <flux:button href="{{ route('matches.index') }}" variant="filled" class="justify-start" icon="calendar-days">
                    Matches
                </flux:button>
                <flux:button href="{{ route('score-imports.index') }}" variant="filled" class="justify-start" icon="arrow-up-tray">
                    Score Imports
                </flux:button>
                <flux:button href="{{ route('standings.index') }}" variant="filled" class="justify-start" icon="chart-bar">
                    Standings
                </flux:button>
            </div>
        </div>
    </div>

    <x-dev-switcher />
</x-layouts.app>
