<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'SAPRF' }}</title>
    <link rel="icon" type="image/png" href="/favicon.png">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|barlow-condensed:600,700,800&display=swap" rel="stylesheet" />

    @fluxAppearance('light')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="force-light min-h-screen bg-stone-50" style="color-scheme: light;">
    <flux:sidebar sticky stashable class="border-r border-stone-200 bg-white">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <a href="{{ url('/') }}" class="block px-2">
            <img src="/saprf-logo-black-text.png" alt="SAPRF" class="h-10 w-auto">
        </a>

        <flux:navlist variant="outline">
            <flux:navlist.group heading="Main">
                <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                    Dashboard
                </flux:navlist.item>
            </flux:navlist.group>

            <flux:navlist.group heading="Competition">
                <flux:navlist.item icon="calendar-days" href="/events" :current="request()->is('events*')">
                    Events
                </flux:navlist.item>
                <flux:navlist.item icon="trophy" href="/standings" :current="request()->is('standings*')">
                    Standings
                </flux:navlist.item>
                <flux:navlist.item icon="identification" :href="route('my-membership')" :current="request()->routeIs('my-membership')">
                    My Membership
                </flux:navlist.item>
                <flux:navlist.item icon="clipboard-document-list" :href="route('registrations.index')" :current="request()->routeIs('registrations.*')">
                    My Registrations
                </flux:navlist.item>
                <flux:navlist.item icon="wrench-screwdriver" :href="route('rifle-configurations.index')" :current="request()->routeIs('rifle-configurations.*')">
                    My Rifles
                </flux:navlist.item>
                <flux:navlist.item icon="fire" :href="route('ammo-loads.index')" :current="request()->routeIs('ammo-loads.*')">
                    My Ammo
                </flux:navlist.item>
                <flux:navlist.item icon="users" :href="route('family.index')" :current="request()->routeIs('family.*')">
                    My Family
                    @if(($familyCount = auth()->user()?->managedAccounts()->count() ?? 0) > 0)
                        <flux:badge size="sm" color="emerald" class="ml-auto">{{ $familyCount }}</flux:badge>
                    @endif
                </flux:navlist.item>
            </flux:navlist.group>

            @role('developer|exco|owner|admin|match_director')
            <flux:navlist.group heading="Match Admin">
                <flux:navlist.item icon="cog-6-tooth" :href="route('matches.index')" :current="request()->routeIs('matches.*')">
                    Manage Matches
                </flux:navlist.item>
                <flux:navlist.item icon="map-pin" :href="route('venues.index')" :current="request()->routeIs('venues.*')">
                    Venues
                </flux:navlist.item>
            </flux:navlist.group>
            @endrole

            @role('developer|exco|owner|admin|match_director')
            <flux:navlist.group heading="Scores">
                <flux:navlist.item icon="arrow-up-tray" :href="route('score-imports.index')" :current="request()->routeIs('score-imports.*')">
                    Score Imports
                </flux:navlist.item>
                <flux:navlist.item icon="document-chart-bar" :href="route('scores.index')" :current="request()->routeIs('scores.*')">
                    Scores
                </flux:navlist.item>
            </flux:navlist.group>
            @endrole

            @role('developer|exco|owner|admin')
            <flux:navlist.group heading="Federation" expandable :expanded="!auth()->user()?->hasRole('exco') && request()->routeIs('approvals.*', 'memberships.*', 'sponsors.*', 'sponsor-tiers.*')">
                <flux:navlist.item icon="check-badge" :href="route('approvals.index')" :current="request()->routeIs('approvals.*')">
                    Approvals
                    @if(($pendingApprovalCount = \App\Http\Controllers\ApprovalController::totalPendingCount()) > 0)
                        <flux:badge size="sm" color="amber" class="ml-auto">{{ $pendingApprovalCount }}</flux:badge>
                    @endif
                </flux:navlist.item>
                <flux:navlist.item icon="users" :href="route('memberships.index')" :current="request()->routeIs('memberships.*')">
                    Memberships
                </flux:navlist.item>
                <flux:navlist.item icon="megaphone" :href="route('sponsors.index')" :current="request()->routeIs('sponsors.*')">
                    Sponsors
                </flux:navlist.item>
                @role('exco|owner')
                <flux:navlist.item icon="tag" :href="route('sponsor-tiers.index')" :current="request()->routeIs('sponsor-tiers.*')">
                    Sponsor Tiers
                </flux:navlist.item>
                <flux:navlist.item icon="building-library" :href="route('provincial-committees.index')" :current="request()->routeIs('provincial-committees.*')">
                    Provincial Committees
                </flux:navlist.item>
                @endrole
            </flux:navlist.group>
            @endrole

            @role('developer|exco|owner|admin')
            <flux:navlist.group heading="Finance" expandable :expanded="!auth()->user()?->hasRole('exco') && request()->routeIs('financials.*')">
                <flux:navlist.item icon="banknotes" :href="route('financials.dashboard')" :current="request()->routeIs('financials.dashboard')">
                    Dashboard
                </flux:navlist.item>
                <flux:navlist.item icon="arrow-trending-up" :href="route('financials.income')" :current="request()->routeIs('financials.income*')">
                    Income
                </flux:navlist.item>
                <flux:navlist.item icon="receipt-percent" :href="route('financials.expenses')" :current="request()->routeIs('financials.expenses*')">
                    Expenses
                </flux:navlist.item>
                <flux:navlist.item icon="credit-card" :href="route('financials.payouts')" :current="request()->routeIs('financials.payouts*')">
                    Payouts
                </flux:navlist.item>
                <flux:navlist.item icon="queue-list" :href="route('financials.transactions')" :current="request()->routeIs('financials.transactions')">
                    Transactions
                </flux:navlist.item>
            </flux:navlist.group>
            @endrole

            @role('developer|exco|owner|admin|provincial_admin')
            <flux:navlist.group heading="Reports" expandable :expanded="!auth()->user()?->hasRole('exco') && request()->routeIs('reports.*', 'sascoc-report.*', 'provincial-members.*')">
                @role('developer|exco|owner|admin')
                <flux:navlist.item icon="chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.index')">
                    Reports Hub
                </flux:navlist.item>
                <flux:navlist.item icon="megaphone" :href="route('reports.sponsorship')" :current="request()->routeIs('reports.sponsorship')">
                    Sponsorship
                </flux:navlist.item>
                <flux:navlist.item icon="trophy" :href="route('reports.selection')" :current="request()->routeIs('reports.selection')">
                    Selection
                </flux:navlist.item>
                <flux:navlist.item icon="chart-bar-square" :href="route('reports.participation')" :current="request()->routeIs('reports.participation')">
                    Participation
                </flux:navlist.item>
                @endrole
                <flux:navlist.item icon="users" :href="route('provincial-members.index')" :current="request()->routeIs('provincial-members.*')">
                    Provincial Members
                </flux:navlist.item>
                @role('exco|owner')
                <flux:navlist.item icon="document-chart-bar" :href="route('sascoc-report.index')" :current="request()->routeIs('sascoc-report.*')">
                    SASCOC Report
                </flux:navlist.item>
                @endrole
            </flux:navlist.group>
            @endrole

            @role('developer|exco|owner')
            <flux:navlist.group heading="Setup" expandable :expanded="!auth()->user()?->hasRole('exco') && request()->routeIs('qualification-rules.*', 'divisions.*', 'site-settings.*', 'user-management.*')">
                <flux:navlist.item icon="cog-6-tooth" :href="route('qualification-rules.index')" :current="request()->routeIs('qualification-rules.*')">
                    Qualification Rules
                </flux:navlist.item>
                <flux:navlist.item icon="squares-2x2" :href="route('divisions.index')" :current="request()->routeIs('divisions.*')">
                    Divisions
                </flux:navlist.item>
                <flux:navlist.item icon="adjustments-horizontal" :href="route('site-settings.index')" :current="request()->routeIs('site-settings.*')">
                    Site Settings
                </flux:navlist.item>
                <flux:navlist.item icon="user-group" :href="route('user-management.index')" :current="request()->routeIs('user-management.*')">
                    User Management
                </flux:navlist.item>
            </flux:navlist.group>
            @endrole

            @role('developer|exco|owner|admin')
            <flux:navlist.group heading="System" expandable :expanded="!auth()->user()?->hasRole('exco') && request()->routeIs('audit-logs.*')">
                <flux:navlist.item icon="document-magnifying-glass" :href="route('audit-logs.index')" :current="request()->routeIs('audit-logs.*')">
                    Audit Logs
                </flux:navlist.item>
            </flux:navlist.group>
            @endrole
        </flux:navlist>

        <flux:spacer />

        <x-sponsors-sidebar placement="app_sidebar" />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="user-circle" :href="route('profile')" :current="request()->routeIs('profile')">
                {{ Auth::user()->name }}
            </flux:navlist.item>
        </flux:navlist>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button type="submit" variant="subtle" class="w-full justify-start" icon="arrow-right-start-on-rectangle">
                Sign Out
            </flux:button>
        </form>
    </flux:sidebar>

    <flux:header class="lg:hidden border-b border-stone-200 bg-white">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <a href="{{ url('/') }}">
            <img src="/saprf-logo-black-text.png" alt="SAPRF" class="h-7 w-auto">
        </a>
    </flux:header>

    <flux:main class="bg-stone-50">
        @if (session('success'))
            <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
