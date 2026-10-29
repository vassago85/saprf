<x-layouts.app :title="$match->name . ' — Settings'">
    @include('matches.workspace._chrome')

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        {{-- Main settings column: edit form --}}
        <div class="space-y-4 lg:col-span-2">
            @include('matches.workspace._settings-form')
            @include('matches.workspace._apply-club-entry')
        </div>

        {{-- Sidebar: add shooter + change director + match tools --}}
        <div class="space-y-4">
            @can('update', $match)
                @include('matches.workspace._add-shooter-panel')
            @endcan

            @can('changeDirector', $match)
                @include('matches.workspace._change-director-panel')
            @endcan

            {{-- Related links (now that the finance report lives in Finances tab) --}}
            <div class="rounded-xl border border-stone-200 bg-white p-5">
                <h3 class="text-sm font-semibold text-stone-900">Match tools</h3>
                <ul class="mt-3 divide-y divide-stone-100 text-sm">
                    <li>
                        <a href="{{ route('matches.export-impact-scoring', $match) }}"
                           class="flex items-center gap-2 py-2 text-stone-700 hover:text-emerald-700">
                            <flux:icon.arrow-down-tray class="size-4 text-stone-400" />
                            Export entries for Impact
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('matches.finances', $match) }}" wire:navigate
                           class="flex items-center gap-2 py-2 text-stone-700 hover:text-emerald-700">
                            <flux:icon.banknotes class="size-4 text-stone-400" />
                            Finances
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('events.show', $match) }}" target="_blank"
                           class="flex items-center gap-2 py-2 text-stone-700 hover:text-emerald-700">
                            <flux:icon.arrow-top-right-on-square class="size-4 text-stone-400" />
                            Public event page
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
