<x-layouts.app>
    <x-slot:title>Owner Dashboard - SAPRF</x-slot:title>

    <div class="space-y-8">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-stone-500">Welcome back, {{ Str::before($user->name, ' ') }}</p>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Owner Dashboard</h1>
            </div>
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-emerald-100 text-emerald-800">Owner</span>
        </div>

        <hr class="border-stone-200 my-6">

        <!-- Membership Fees Overview -->
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="font-heading text-xl font-bold text-stone-900">Membership Fees</h2>
                <a href="{{ route('fees.index') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 transition-colors">
                    Manage Fees
                </a>
            </div>
            @if(($feeTiers ?? collect())->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($feeTiers as $tier)
                        <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                            <p class="text-xs text-stone-500 uppercase tracking-wider flex items-center gap-1.5">
                                {{ $tier->name }}
                                @if($tier->is_default)<span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[9px] font-semibold bg-emerald-100 text-emerald-800">Default</span>@endif
                            </p>
                            <p class="text-xl font-bold text-stone-900 mt-1">R{{ number_format((float) $tier->price, 2) }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-stone-500">No membership fees configured yet. <a href="{{ route('fees.index') }}" class="text-emerald-700 font-medium hover:text-emerald-800">Add one</a> to get started.</p>
            @endif
        </div>

        <!-- Match Fee Settings Overview -->
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="font-heading text-xl font-bold text-stone-900">Match Fee Settings</h2>
                <a href="{{ route('site-settings.index') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 transition-colors">
                    Edit Settings
                </a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                    <p class="text-xs text-stone-500 uppercase tracking-wider">Non-Member Surcharge</p>
                    <p class="text-xl font-bold text-stone-900 mt-1">R{{ number_format((float)($settings['non_member_surcharge'] ?? 0), 2) }}</p>
                </div>
                <div class="rounded-lg bg-stone-50 border border-stone-200 p-4">
                    <p class="text-xs text-stone-500 uppercase tracking-wider">Lapsed Surcharge</p>
                    <p class="text-xl font-bold text-stone-900 mt-1">R{{ number_format((float)($settings['lapsed_member_surcharge'] ?? 0), 2) }}</p>
                </div>
            </div>
        </div>

        <!-- Qualification Rules Summary -->
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="font-heading text-xl font-bold text-stone-900">Qualification Rules</h2>
                <a href="{{ route('qualification-rules.index') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-800 transition-colors">
                    Manage Rules
                </a>
            </div>
            <p class="text-sm text-stone-500">
                {{ $qualificationRulesCount ?? 0 }} rule{{ ($qualificationRulesCount ?? 0) !== 1 ? 's' : '' }} configured across all series and seasons.
            </p>
        </div>

        <!-- Platform Stats -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <p class="text-sm text-stone-500">Total Members</p>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($totalMembers ?? 0) }}</p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <p class="text-sm text-stone-500">Active Memberships</p>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($activeMemberships ?? 0) }}</p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <p class="text-sm text-stone-500">Total Matches</p>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($totalMatches ?? 0) }}</p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-2">
                <p class="text-sm text-stone-500">Total Scores</p>
                <p class="text-3xl font-bold text-stone-900">{{ number_format($totalScores ?? 0) }}</p>
            </div>
        </div>

        <hr class="border-stone-200 my-6">

        <!-- Quick Links -->
        <div>
            <h2 class="font-heading text-xl font-bold text-stone-900 mb-4">Quick Links</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <flux:button href="{{ route('site-settings.index') }}" variant="filled" class="justify-start" icon="adjustments-horizontal">
                    Site Settings
                </flux:button>
                <flux:button href="{{ route('user-management.index') }}" variant="filled" class="justify-start" icon="user-group">
                    User Management
                </flux:button>
                <flux:button href="{{ route('qualification-rules.index') }}" variant="filled" class="justify-start" icon="cog-6-tooth">
                    Qualification Rules
                </flux:button>
                <flux:button href="{{ route('memberships.index') }}" variant="filled" class="justify-start" icon="users">
                    Memberships
                </flux:button>
                <flux:button href="{{ route('matches.index') }}" variant="filled" class="justify-start" icon="calendar-days">
                    Matches
                </flux:button>
                <flux:button href="{{ route('audit-logs.index') }}" variant="filled" class="justify-start" icon="document-magnifying-glass">
                    Audit Logs
                </flux:button>
            </div>
        </div>
    </div>

    <x-dev-switcher />
</x-layouts.app>
