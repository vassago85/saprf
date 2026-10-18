<x-layouts.app>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-stone-900">Load tools</h1>
                <p class="mt-1 text-sm text-stone-600">
                    Workshop tools that sit between your rifles, barrels, and ammo loads. Pick an analyser to open its history.
                </p>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-4 sm:grid-cols-2">
        <a href="{{ route('ladder-sessions.index') }}"
           class="group flex flex-col gap-2 rounded-lg border border-stone-200 bg-white p-5 shadow-sm transition hover:border-emerald-300 hover:shadow-md">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-md bg-emerald-50 text-emerald-700 group-hover:bg-emerald-100">
                    <flux:icon.chart-bar class="h-5 w-5" />
                </div>
                <h2 class="text-base font-semibold text-stone-900 group-hover:text-emerald-800">Ladder analyser</h2>
            </div>
            <p class="text-sm text-stone-600">
                Pressure-ladder sessions across a charge range. Logs each shot, flags vertical nodes, and exports to CSV.
            </p>
        </a>

        <a href="{{ route('ammo-strings.index') }}"
           class="group flex flex-col gap-2 rounded-lg border border-stone-200 bg-white p-5 shadow-sm transition hover:border-emerald-300 hover:shadow-md">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-md bg-emerald-50 text-emerald-700 group-hover:bg-emerald-100">
                    <flux:icon.fire class="h-5 w-5" />
                </div>
                <h2 class="text-base font-semibold text-stone-900 group-hover:text-emerald-800">String analyser</h2>
            </div>
            <p class="text-sm text-stone-600">
                Confirmation strings for a single load. Enter shots in fire order to see group size, SD, and ES at a glance.
            </p>
        </a>
    </div>
</x-layouts.app>
