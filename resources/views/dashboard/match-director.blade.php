<x-layouts.app>
    <x-slot:title>Match Director Dashboard - SAPRF</x-slot:title>

    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-stone-500">Welcome back, {{ Str::before($user->name, ' ') }}</p>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Match Director Dashboard</h1>
            </div>
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Match Director</span>
        </div>

        <hr class="border-stone-200 my-6">

        <!-- Status Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <p class="text-sm text-stone-500">My Matches</p>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($myMatchesCount ?? 0) }}</p>
                <a href="{{ route('matches.index', ['mine' => true]) }}" class="inline-flex items-center justify-center w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-1.5 text-sm font-medium text-stone-700 hover:bg-stone-100 transition-colors">
                    View My Matches
                </a>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <p class="text-sm text-stone-500">My Upcoming Matches</p>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($myUpcomingMatches ?? 0) }}</p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-stone-500">Pending Registrations</p>
                    @if(($pendingRegistrations ?? 0) > 0)
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800">{{ $pendingRegistrations }}</span>
                    @endif
                </div>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($pendingRegistrations ?? 0) }}</p>
            </div>
        </div>

        <hr class="border-stone-200 my-6">

        <!-- Quick Links -->
        <div>
            <h2 class="font-heading text-xl font-bold text-stone-900 mb-4">Quick Links</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <a href="{{ route('matches.create') }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition-colors">
                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" /></svg>
                    Create Match
                </a>
                <flux:button href="{{ route('matches.index', ['mine' => true]) }}" variant="filled" class="justify-start" icon="calendar-days">
                    My Matches
                </flux:button>
                <flux:button href="{{ route('score-imports.index') }}" variant="filled" class="justify-start" icon="arrow-up-tray">
                    Upload Scores
                </flux:button>
            </div>
        </div>
    </div>

    <x-dev-switcher />
</x-layouts.app>
