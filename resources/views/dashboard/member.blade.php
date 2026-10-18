<x-layouts.app>
    <x-slot:title>Dashboard - SAPRF</x-slot:title>

    {{-- One-line reminder when a staff user has switched into shooter mode.
         Dismissed banner stays dismissed for the session (sessionStorage). --}}
    @if(auth()->user()->canSwitchViewMode())
        <div x-data="{ open: (sessionStorage.getItem('saprf.shooterBannerDismissed') === '1' ? false : true) }"
             x-show="open" x-cloak
             class="mb-4 flex items-center justify-between gap-3 rounded-md border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs">
            <div class="flex min-w-0 items-center gap-2 text-sky-900">
                <svg class="size-3.5 shrink-0 text-sky-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.412 15.655.706-.706m-.706.706-3.032 3.032a1.5 1.5 0 0 1-2.121 0l-2.29-2.29a1.5 1.5 0 0 1 0-2.122L7.001 11.253l.706-.706m3.705 5.108-3.705-5.108"/></svg>
                <span class="truncate">You're in shooter mode. Admin tools are hidden until you switch back.</span>
                <form method="POST" action="{{ route('dashboard.view-mode') }}" class="shrink-0">
                    @csrf
                    <input type="hidden" name="mode" value="admin">
                    <button type="submit" class="font-semibold text-sky-700 underline hover:text-sky-900">Switch to admin</button>
                </form>
            </div>
            <button type="button"
                    @click="sessionStorage.setItem('saprf.shooterBannerDismissed', '1'); open = false"
                    class="shrink-0 rounded p-0.5 text-sky-500 hover:bg-sky-100 hover:text-sky-800"
                    aria-label="Dismiss">
                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>
    @endif

    <livewire:dashboard.shooter-dashboard />

    <x-dev-switcher />
</x-layouts.app>
