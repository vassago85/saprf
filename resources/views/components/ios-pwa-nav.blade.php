{{--
    iOS PWA bottom navigation.

    Only renders when BOTH conditions are true:
      1. Device is iPhone / iPad / iPod (detected client-side).
      2. Page is running as an installed PWA (standalone display mode
         or `window.navigator.standalone === true` — the iOS-specific
         flag Safari sets when launched from the home screen).

    Rationale: an installed iOS PWA has NO Safari chrome — no back
    button, no address bar, no tab bar. Users get stranded on deep
    pages because iOS also doesn't have Android's system back gesture
    in standalone webviews. This bar restores the four things they
    need to move around: Back, Home, Communications, Menu.

    We deliberately hide it on Android (their PWA still gets the
    system back button / swipe) and in normal Safari (users have the
    browser chrome), because painting a duplicate nav bar there adds
    clutter without solving anything.

    Auth-only: rendered inside `@auth` upstream — the four targets
    (dashboard, communications, sidebar) are all logged-in flows.
--}}

<div
    x-data="iosPwaNav()"
    x-init="init()"
    x-show="visible"
    x-cloak
    class="fixed inset-x-0 bottom-0 z-40 border-t border-stone-200 bg-white/95 backdrop-blur-sm shadow-[0_-4px_12px_-6px_rgba(0,0,0,0.08)]"
    style="padding-bottom: env(safe-area-inset-bottom, 0px);"
    role="navigation"
    aria-label="Primary mobile navigation"
>
    <div class="mx-auto grid h-14 max-w-md grid-cols-4 items-stretch text-stone-700">
        {{-- Back --}}
        <button
            type="button"
            @click="goBack()"
            class="flex flex-col items-center justify-center gap-0.5 text-[10px] font-medium hover:bg-stone-100 active:bg-stone-200 transition"
            aria-label="Go back"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/>
            </svg>
            <span>Back</span>
        </button>

        {{-- Home / Dashboard --}}
        <a
            href="{{ route('dashboard') }}"
            class="flex flex-col items-center justify-center gap-0.5 text-[10px] font-medium hover:bg-stone-100 active:bg-stone-200 transition {{ request()->routeIs('dashboard') ? 'text-emerald-700' : '' }}"
            aria-label="Dashboard"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>
            </svg>
            <span>Home</span>
        </a>

        {{-- Communications with unread badge --}}
        <a
            href="{{ route('communications.index') }}"
            x-data="{ unread: {{ (int) \App\Models\AnnouncementRecipient::query()->where('user_id', auth()->id())->whereNull('read_at')->count() }} }"
            x-init="setInterval(async () => {
                try {
                    const r = await fetch('{{ route('communications.unread-count') }}', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                    if (r.ok) { const j = await r.json(); unread = j.unread; }
                } catch (e) {}
            }, 30000)"
            class="relative flex flex-col items-center justify-center gap-0.5 text-[10px] font-medium hover:bg-stone-100 active:bg-stone-200 transition {{ request()->routeIs('communications.*') ? 'text-emerald-700' : '' }}"
            aria-label="Communications"
        >
            <div class="relative">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
                </svg>
                <span
                    x-show="unread > 0"
                    x-cloak
                    class="absolute -top-1 -right-2 inline-flex min-w-[1.1rem] items-center justify-center rounded-full bg-emerald-600 px-1 text-[10px] font-semibold text-white"
                    x-text="unread"
                ></span>
            </div>
            <span>Inbox</span>
        </a>

        {{-- Menu (opens the Flux sidebar via the global event the layout listens for) --}}
        <button
            type="button"
            @click="openSidebar()"
            class="flex flex-col items-center justify-center gap-0.5 text-[10px] font-medium hover:bg-stone-100 active:bg-stone-200 transition"
            aria-label="Open menu"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/>
            </svg>
            <span>Menu</span>
        </button>
    </div>
</div>

@once
    @push('scripts')
        <script>
            function iosPwaNav() {
                return {
                    visible: false,

                    init() {
                        const ua = window.navigator.userAgent || '';
                        // iPadOS 13+ reports as Mac Safari — the touch check
                        // is the workaround Apple themselves recommend.
                        const isIpad = /Macintosh/.test(ua) && navigator.maxTouchPoints > 1;
                        const isIos = /iphone|ipad|ipod/i.test(ua) || isIpad;

                        const isStandalone =
                            window.matchMedia('(display-mode: standalone)').matches
                            || window.navigator.standalone === true;

                        this.visible = isIos && isStandalone;

                        // Flag the body so a matching CSS rule can add the
                        // bottom padding that keeps content from being hidden
                        // behind the fixed bar. Set only when the bar is
                        // actually visible to avoid a wasted 4rem gap for
                        // 99% of visitors.
                        if (this.visible) {
                            document.body.dataset.iosPwa = 'true';
                        }
                    },

                    goBack() {
                        // history.length starts at 1 on the launch page;
                        // going back would either do nothing or leave the
                        // PWA shell — send them home instead.
                        if (window.history.length > 1) {
                            window.history.back();
                        } else {
                            window.location.href = '{{ route('dashboard') }}';
                        }
                    },

                    openSidebar() {
                        // Same event the mobile header's hamburger uses;
                        // the body-level Alpine listener toggles sidebarOpen.
                        window.dispatchEvent(new CustomEvent('flux-sidebar-toggle'));
                    },
                };
            }
        </script>
        <style>
            /* Extra scroll padding so content isn't hidden behind the fixed
               nav bar. Applied only when the JS above has confirmed we're
               inside an iOS PWA. */
            body[data-ios-pwa="true"] {
                padding-bottom: calc(3.5rem + env(safe-area-inset-bottom, 0px));
            }
        </style>
    @endpush
@endonce
