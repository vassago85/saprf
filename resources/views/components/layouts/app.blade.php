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
<body class="force-light min-h-screen bg-stone-50" style="color-scheme: light;"
    x-data="{ sidebarOpen: false }"
    x-effect="sidebarOpen ? $el.setAttribute('data-sidebar-open', '') : $el.removeAttribute('data-sidebar-open')"
    @flux-sidebar-toggle.window="sidebarOpen = !sidebarOpen"
    @keydown.escape.window="sidebarOpen = false"
    @click="if ($event.target.closest('[data-flux-sidebar] a')) sidebarOpen = false">
    {{-- Mobile sidebar backdrop (Flux Free doesn't ship this). --}}
    <div
        x-show="sidebarOpen"
        x-transition.opacity.duration.150ms
        x-cloak
        @click="sidebarOpen = false"
        class="fixed inset-0 z-30 bg-black/40 lg:hidden"
        aria-hidden="true"
    ></div>

    <flux:sidebar sticky stashable class="border-r border-stone-200 bg-white">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <a href="{{ url('/') }}" class="block px-2">
            <img src="/saprf-logo-black-text.png" alt="SAPRF" class="h-10 w-auto">
        </a>

        {{-- View-mode toggle: staff users can flip between their admin console
             and their own shooter (member) experience. Members-only never see this. --}}
        @php
            $viewMode = auth()->user()?->effectiveViewMode() ?? 'shooter';
            $canSwitchView = (bool) auth()->user()?->canSwitchViewMode();
        @endphp
        @if($canSwitchView)
            <div class="px-2 pt-2">
                <div class="mb-1.5 text-[10px] font-semibold uppercase tracking-widest text-stone-400">View as</div>
                <div class="flex rounded-lg bg-stone-100 p-0.5 ring-1 ring-inset ring-stone-200">
                    <form method="POST" action="{{ route('dashboard.view-mode') }}" class="flex-1">
                        @csrf
                        <input type="hidden" name="mode" value="admin">
                        <button type="submit"
                                class="w-full inline-flex items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-semibold transition
                                       {{ $viewMode === 'admin'
                                          ? 'bg-white text-stone-900 shadow-sm ring-1 ring-inset ring-stone-200'
                                          : 'text-stone-500 hover:text-stone-800' }}">
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                            Admin
                        </button>
                    </form>
                    <form method="POST" action="{{ route('dashboard.view-mode') }}" class="flex-1">
                        @csrf
                        <input type="hidden" name="mode" value="shooter">
                        <button type="submit"
                                class="w-full inline-flex items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-semibold transition
                                       {{ $viewMode === 'shooter'
                                          ? 'bg-white text-stone-900 shadow-sm ring-1 ring-inset ring-stone-200'
                                          : 'text-stone-500 hover:text-stone-800' }}">
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.412 15.655.706-.706m-.706.706-3.032 3.032a1.5 1.5 0 0 1-2.121 0l-2.29-2.29a1.5 1.5 0 0 1 0-2.122L7.001 11.253l.706-.706m3.705 5.108-3.705-5.108m3.705 5.108L15.68 12.19m-7.973-1.643L11.412 4.84l4.268 4.268-3.706 3.083m-4.267-1.644L15.68 12.19m-7.973-1.643 4.268-4.267"/></svg>
                            Shooter
                        </button>
                    </form>
                </div>
            </div>
        @endif

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
                <flux:navlist.item icon="document-text" :href="route('selection.policy.public', ['series' => 'pr22'])" :current="request()->routeIs('selection.policy.public') && request()->route('series') === 'pr22'">
                    PR22 Team Selection
                </flux:navlist.item>
                <flux:navlist.item icon="document-text" :href="route('selection.policy.public', ['series' => 'prs'])" :current="request()->routeIs('selection.policy.public') && request()->route('series') === 'prs'">
                    PRS Team Selection
                </flux:navlist.item>
            </flux:navlist.group>

            {{-- Everything below is hidden when a staff user has flipped to
                 Shooter mode, giving them a member-only sidebar. --}}
            @if($viewMode === 'admin')
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
            <flux:navlist.group heading="Federation" expandable :expanded="!auth()->user()?->hasRole('exco') && request()->routeIs('approvals.*', 'memberships.*', 'clubs.*', 'contact-messages.*', 'sponsors.*', 'sponsor-tiers.*')">
                <flux:navlist.item icon="check-badge" :href="route('approvals.index')" :current="request()->routeIs('approvals.*')">
                    Approvals
                    @if(($pendingApprovalCount = \App\Http\Controllers\ApprovalController::totalPendingCount()) > 0)
                        <flux:badge size="sm" color="amber" class="ml-auto">{{ $pendingApprovalCount }}</flux:badge>
                    @endif
                </flux:navlist.item>
                <flux:navlist.item icon="users" :href="route('memberships.index')" :current="request()->routeIs('memberships.*')">
                    Memberships
                </flux:navlist.item>
                <flux:navlist.item icon="building-office-2" :href="route('clubs.index')" :current="request()->routeIs('clubs.*')">
                    Clubs
                </flux:navlist.item>
                <flux:navlist.item icon="envelope" :href="route('contact-messages.index')" :current="request()->routeIs('contact-messages.*')">
                    Contact Enquiries
                    @php($unhandled = \App\Models\ContactMessage::query()->clean()->unhandled()->count())
                    @if($unhandled > 0)
                        <flux:badge size="sm" color="amber" class="ml-auto">{{ $unhandled }}</flux:badge>
                    @endif
                </flux:navlist.item>
                <flux:navlist.item icon="megaphone" :href="route('sponsors.index')" :current="request()->routeIs('sponsors.*')">
                    Sponsors
                </flux:navlist.item>
                @role('developer|exco|owner')
                <flux:navlist.item icon="tag" :href="route('sponsor-tiers.index')" :current="request()->routeIs('sponsor-tiers.*')">
                    Sponsor Tiers
                </flux:navlist.item>
                <flux:navlist.item icon="building-library" :href="route('provincial-committees.index')" :current="request()->routeIs('provincial-committees.*')">
                    Provincial Committees
                </flux:navlist.item>
                @endrole
            </flux:navlist.group>
            @endrole

            @role('developer|exco|owner|admin|iprf_selector')
            <flux:navlist.group heading="IPRF Selection" expandable :expanded="request()->routeIs('selection.*')">
                <flux:navlist.item icon="flag" :href="route('selection.cycles.index')" :current="request()->routeIs('selection.cycles.index', 'selection.cycles.create', 'selection.cycles.edit')">
                    Selection Cycles
                </flux:navlist.item>
                <flux:navlist.item icon="document-text" :href="route('selection.policy.public', ['series' => 'pr22'])" :current="request()->routeIs('selection.policy.public') && request()->route('series') === 'pr22'">
                    PR22 Policy (public)
                </flux:navlist.item>
                <flux:navlist.item icon="document-text" :href="route('selection.policy.public', ['series' => 'prs'])" :current="request()->routeIs('selection.policy.public') && request()->route('series') === 'prs'">
                    PRS Policy (public)
                </flux:navlist.item>
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
            <flux:navlist.group heading="Setup" expandable :expanded="!auth()->user()?->hasRole('exco') && request()->routeIs('qualification-rules.*', 'divisions.*', 'fees.*', 'site-settings.*', 'user-management.*')">
                <flux:navlist.item icon="cog-6-tooth" :href="route('qualification-rules.index')" :current="request()->routeIs('qualification-rules.*')">
                    Qualification Rules
                </flux:navlist.item>
                <flux:navlist.item icon="squares-2x2" :href="route('divisions.index')" :current="request()->routeIs('divisions.*')">
                    Divisions
                </flux:navlist.item>
                <flux:navlist.item icon="banknotes" :href="route('fees.index')" :current="request()->routeIs('fees.*')">
                    Membership Fees
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
            @endif {{-- $viewMode === 'admin' --}}
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
