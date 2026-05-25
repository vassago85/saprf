<x-layouts.app>
    <x-slot:title>Provincial Admin Dashboard - SAPRF</x-slot:title>

    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-stone-500">Welcome back, {{ Str::before($user->name, ' ') }}</p>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Provincial Admin</h1>
            </div>
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Provincial Admin</span>
        </div>

        <hr class="border-stone-200 my-6">

        {{-- Committee Positions --}}
        @if($committeePositions->isNotEmpty())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4">
                <h2 class="font-heading text-xl font-bold text-stone-900">Your Committee Positions</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($committeePositions as $position)
                        <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                            <p class="text-xs text-stone-500 uppercase tracking-wider">{{ $position->positionLabel() }}</p>
                            <p class="text-lg font-bold text-stone-900 mt-1">{{ $position->province?->name ?? '—' }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6">
                <p class="text-sm text-amber-900">You have no active committee positions assigned. Contact a federation admin to be added to a provincial committee.</p>
            </div>
        @endif

        {{-- Stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <p class="text-sm text-stone-500">Members in your province{{ $committeePositions->count() === 1 ? '' : 's' }}</p>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($provincialMembersCount) }}</p>
                <a href="{{ route('provincial-members.index') }}" class="inline-flex items-center justify-center w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100 transition-colors">
                    View Members
                </a>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <p class="text-sm text-stone-500">Active memberships</p>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($provincialActiveMembersCount) }}</p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <p class="text-sm text-stone-500">Upcoming matches</p>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($upcomingProvincialMatches->count()) }}</p>
            </div>
        </div>

        {{-- Upcoming Provincial Matches --}}
        @if($upcomingProvincialMatches->isNotEmpty())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-stone-200 flex items-center justify-between">
                    <h2 class="font-heading text-xl font-bold text-stone-900">Upcoming Matches in Your Province{{ $committeePositions->count() === 1 ? '' : 's' }}</h2>
                    <a href="{{ route('matches.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800 transition-colors">
                        View All &rarr;
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-stone-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Date</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Match</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-stone-500">Province</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($upcomingProvincialMatches as $match)
                                <tr class="hover:bg-stone-50">
                                    <td class="px-5 py-3 text-stone-600 whitespace-nowrap">{{ $match->match_date?->format('d M Y') }}</td>
                                    <td class="px-5 py-3 font-medium text-stone-900">{{ $match->name }}</td>
                                    <td class="px-5 py-3 text-stone-600">{{ $match->province?->abbreviation ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <hr class="border-stone-200 my-6">

        {{-- Quick Links --}}
        <div>
            <h2 class="font-heading text-xl font-bold text-stone-900 mb-4">Quick Links</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <flux:button href="{{ route('provincial-members.index') }}" variant="filled" class="justify-start" icon="users">
                    Provincial Members
                </flux:button>
                <flux:button href="{{ route('matches.index') }}" variant="filled" class="justify-start" icon="calendar-days">
                    Matches
                </flux:button>
                <flux:button href="{{ route('standings.index') }}" variant="filled" class="justify-start" icon="trophy">
                    Standings
                </flux:button>
            </div>
        </div>
    </div>

    <x-dev-switcher />
</x-layouts.app>
