<x-layouts.app>
    <x-slot:title>Dashboard - SAPRF</x-slot:title>

    {{-- Reminder banner when a staff user has switched into shooter mode. --}}
    @if(auth()->user()->canSwitchViewMode())
        <div class="mb-6 rounded-xl border border-sky-200 bg-sky-50 p-4 flex items-center justify-between gap-4">
            <div class="flex items-start gap-3">
                <svg class="size-5 text-sky-600 shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m11.412 15.655.706-.706m-.706.706-3.032 3.032a1.5 1.5 0 0 1-2.121 0l-2.29-2.29a1.5 1.5 0 0 1 0-2.122L7.001 11.253l.706-.706m3.705 5.108-3.705-5.108m3.705 5.108L15.68 12.19m-7.973-1.643L11.412 4.84l4.268 4.268-3.706 3.083m-4.267-1.644L15.68 12.19m-7.973-1.643 4.268-4.267"/>
                </svg>
                <div>
                    <p class="text-sm font-semibold text-sky-900">Shooter mode</p>
                    <p class="text-xs text-sky-800/80 mt-0.5">You're viewing your own shooter dashboard. Admin tools are hidden until you switch back.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('dashboard.view-mode') }}">
                @csrf
                <input type="hidden" name="mode" value="admin">
                <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-200 hover:bg-sky-100 whitespace-nowrap">
                    Switch to Admin
                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                </button>
            </form>
        </div>
    @endif

    <livewire:dashboard.shooter-dashboard />

    <x-dev-switcher />
</x-layouts.app>
