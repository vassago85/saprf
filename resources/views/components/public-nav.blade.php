@props(['current' => ''])

<nav class="sticky top-0 z-50 bg-white/95 backdrop-blur border-b border-stone-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6">
        <div class="flex items-center justify-between h-16">
            <a href="/" class="shrink-0">
                <img src="/saprf-logo-black-text.png" alt="SAPRF" class="h-9 w-auto">
            </a>

            {{-- Desktop nav --}}
            <div class="hidden md:flex items-center gap-1">
                <a href="/events"
                   class="px-3 py-2 rounded-lg text-sm font-medium transition {{ $current === 'events' ? 'text-emerald-700 bg-emerald-50' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                    Events
                </a>
                <a href="/standings"
                   class="px-3 py-2 rounded-lg text-sm font-medium transition {{ $current === 'standings' ? 'text-emerald-700 bg-emerald-50' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                    Standings
                </a>
                <a href="{{ route('documents.index') }}"
                   class="px-3 py-2 rounded-lg text-sm font-medium transition {{ $current === 'documents' ? 'text-emerald-700 bg-emerald-50' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                    Documents
                </a>
                <a href="{{ route('contact.create') }}"
                   class="px-3 py-2 rounded-lg text-sm font-medium transition {{ $current === 'contact' ? 'text-emerald-700 bg-emerald-50' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                    Contact
                </a>
                @auth
                    <a href="/dashboard"
                       class="px-3 py-2 rounded-lg text-sm font-medium transition {{ $current === 'dashboard' ? 'text-emerald-700 bg-emerald-50' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-50' }}">
                        Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}"
                       class="px-3 py-2 rounded-lg text-sm font-medium text-stone-600 hover:text-stone-900 hover:bg-stone-50 transition">
                        Login
                    </a>
                    <a href="{{ route('register') }}"
                       class="ml-2 px-5 py-2 rounded-xl bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800 transition shadow-sm">
                        Join SAPRF
                    </a>
                @endauth
            </div>

            {{-- Mobile hamburger --}}
            <div class="md:hidden" x-data="{ open: false }">
                <button @click="open = !open" class="p-2 rounded-lg text-stone-500 hover:bg-stone-100 transition">
                    <svg x-show="!open" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                    <svg x-show="open" x-cloak class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
                <div x-show="open" x-cloak @click.away="open = false"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="absolute top-16 inset-x-0 bg-white border-b border-stone-200 shadow-lg z-50">
                    <div class="px-4 py-3 space-y-1">
                        <a href="/events" class="block px-3 py-2 rounded-lg text-sm font-medium {{ $current === 'events' ? 'text-emerald-700 bg-emerald-50' : 'text-stone-700 hover:bg-stone-50' }}">Events</a>
                        <a href="/standings" class="block px-3 py-2 rounded-lg text-sm font-medium {{ $current === 'standings' ? 'text-emerald-700 bg-emerald-50' : 'text-stone-700 hover:bg-stone-50' }}">Standings</a>
                        <a href="{{ route('documents.index') }}" class="block px-3 py-2 rounded-lg text-sm font-medium {{ $current === 'documents' ? 'text-emerald-700 bg-emerald-50' : 'text-stone-700 hover:bg-stone-50' }}">Documents</a>
                        <a href="{{ route('contact.create') }}" class="block px-3 py-2 rounded-lg text-sm font-medium {{ $current === 'contact' ? 'text-emerald-700 bg-emerald-50' : 'text-stone-700 hover:bg-stone-50' }}">Contact</a>
                        @auth
                            <a href="/dashboard" class="block px-3 py-2 rounded-lg text-sm font-medium text-stone-700 hover:bg-stone-50">Dashboard</a>
                        @else
                            <a href="{{ route('login') }}" class="block px-3 py-2 rounded-lg text-sm font-medium text-stone-700 hover:bg-stone-50">Login</a>
                            <a href="{{ route('register') }}" class="block px-3 py-2.5 mt-2 rounded-xl bg-emerald-700 text-white text-sm font-semibold text-center">Join SAPRF</a>
                        @endauth
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>
