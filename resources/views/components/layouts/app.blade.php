<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-google-tag />
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'SAPRF' }}</title>
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" type="image/png" sizes="192x192" href="/favicon.png">
    <meta name="robots" content="noindex, nofollow">

    <x-pwa-meta />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|barlow-condensed:600,700,800&display=swap" rel="stylesheet" />

    @fluxAppearance('light')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="force-light min-h-screen bg-stone-50" style="color-scheme: light;"
    x-data="{ sidebarOpen: false }"
    x-init="$el.querySelectorAll('[data-flux-sidebar-cloak]').forEach((n) => n.removeAttribute('data-flux-sidebar-cloak'))"
    x-effect="sidebarOpen ? $el.setAttribute('data-sidebar-open', '') : $el.removeAttribute('data-sidebar-open')"
    @flux-sidebar-toggle.window="sidebarOpen = !sidebarOpen"
    @keydown.escape.window="sidebarOpen = false"
    @click="if ($event.target.closest('[data-flux-sidebar] a')) sidebarOpen = false">
    <x-skip-link />

    {{-- Developer impersonation banner. Fixed to the top of every
         authenticated page (admin console AND public pages routed
         through x-layouts.public, which nests this layout). Only
         renders when session.impersonator_id is set — a developer has
         explicitly assumed another member's identity via
         /impersonate/{id}. Kept red + prominent so a developer can't
         accidentally take actions (send messages, revoke memberships)
         while thinking they're on their own account. --}}
    @if(session('impersonator_id'))
        <div class="sticky top-0 z-50 bg-red-600 text-white shadow-md">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-2 text-sm font-medium">
                <div class="flex items-center gap-2 min-w-0">
                    <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /></svg>
                    <span class="truncate">
                        <strong>Impersonating:</strong>
                        {{ auth()->user()?->name ?? 'member' }}
                        <span class="hidden sm:inline text-red-100">— signed in as {{ session('impersonator_name', 'developer') }}</span>
                    </span>
                </div>
                <a href="{{ route('impersonate.stop') }}"
                   class="shrink-0 inline-flex items-center gap-1 rounded-md bg-white/15 px-3 py-1 text-xs font-semibold hover:bg-white/25 transition ring-1 ring-inset ring-white/30">
                    Return to yourself
                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" /></svg>
                </a>
            </div>
        </div>
    @endif

    {{-- Mobile sidebar backdrop (Flux Free doesn't ship this). --}}
    <div
        x-show="sidebarOpen"
        x-transition.opacity.duration.150ms
        x-cloak
        @click="sidebarOpen = false"
        class="fixed inset-0 z-10 bg-black/40 lg:hidden"
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

        <x-sidebar-nav />

        <flux:spacer />

        <x-sponsors-sidebar placement="app_sidebar" />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="user-circle" :href="route('profile')" :current="request()->routeIs('profile')">
                {{ Auth::user()->name }}
            </flux:navlist.item>
        </flux:navlist>

        {{-- Sign Out lives in its own form because logout must be a POST
             (CSRF protected). Styled to match the navlist rows above so
             members recognise it as a nav action rather than a random
             button. The extra bottom margin keeps it clear of iOS home-
             indicator gestures and the sidebar's own padding-bottom that
             kicks in for installed PWAs (see app.css). --}}
        <form method="POST" action="{{ route('logout') }}" class="mt-1">
            @csrf
            <button
                type="submit"
                class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-stone-700 hover:bg-stone-100 hover:text-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
            >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-5 shrink-0">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/>
                </svg>
                <span>Sign Out</span>
            </button>
        </form>
    </flux:sidebar>

    <flux:header class="lg:hidden border-b border-stone-200 bg-white">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        {{-- Compact notification bell — polls unread-count endpoint every 30s. --}}
        @auth
            <a href="{{ route('communications.index') }}"
                x-data="{ unread: {{ \App\Models\AnnouncementRecipient::query()->where('user_id', auth()->id())->whereNull('read_at')->count() }} }"
                x-init="setInterval(async () => {
                    try {
                        const r = await fetch('{{ route('communications.unread-count') }}', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                        if (r.ok) { const j = await r.json(); unread = j.unread; }
                    } catch (e) {}
                }, 30000)"
                class="relative mr-3 inline-flex items-center rounded-lg p-2 text-stone-600 hover:bg-stone-100"
                aria-label="Communications">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
                </svg>
                <span x-show="unread > 0" x-cloak
                    class="absolute -top-0.5 -right-0.5 inline-flex min-w-[1.1rem] items-center justify-center rounded-full bg-emerald-600 px-1 text-[10px] font-semibold text-white"
                    x-text="unread"></span>
            </a>
        @endauth

        <a href="{{ url('/') }}">
            <img src="/saprf-logo-black-text.png" alt="SAPRF" class="h-7 w-auto">
        </a>
    </flux:header>

    <flux:main id="main" class="bg-stone-50">
        <x-outstanding-acknowledgements />
        <x-push-nudge />

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

    @auth
        <x-ios-pwa-nav />
    @endauth

    @stack('scripts')
    @fluxScripts
</body>
</html>
