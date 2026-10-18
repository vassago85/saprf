<x-layouts.app :title="'National Team & Protea Colours - SAPRF'">
@php /* Pass the title via a PHP variable so the ampersand isn't double-escaped
       (Blade's attribute parser treats a bare & as an HTML entity start). */ @endphp
@php $appearancesPageTitle = 'National Team & Protea Colours'; @endphp
    <div class="space-y-6">
        <x-page-header :title="$appearancesPageTitle">
            <p class="mt-1 text-sm text-stone-500">
                Every year a shooter has represented South Africa. The
                <span class="inline-flex items-center gap-1 font-semibold text-emerald-700">
                    <svg class="size-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/></svg>
                    Colours
                </span>
                badge marks the one-time appearance that granted the shooter their Protea Colours.
            </p>
            <x-slot:actions>
                <a href="{{ route('appearances.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Record Appearance
                </a>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        <x-filter-bar :action="route('appearances.index')" label="Appearance filters">
                <div>
                    <label for="filter_search" class="block text-xs font-medium text-stone-500 mb-1">Shooter</label>
                    <input type="text" id="filter_search" name="search" value="{{ request('search') }}" placeholder="Name..."
                        class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label for="filter_year" class="block text-xs font-medium text-stone-500 mb-1">Year</label>
                    <select id="filter_year" name="year" class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All</option>
                        @foreach($availableYears as $y)
                            <option value="{{ $y }}" @selected((string) request('year') === (string) $y)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="filter_series" class="block text-xs font-medium text-stone-500 mb-1">Series</label>
                    <select id="filter_series" name="series" class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All</option>
                        <option value="PRS" @selected(request('series') === 'PRS')>PRS</option>
                        <option value="PR22" @selected(request('series') === 'PR22')>PR22</option>
                    </select>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-stone-600 pb-2.5">
                    <input type="checkbox" name="colours_only" value="1" @checked(request()->boolean('colours_only'))
                           class="rounded border-stone-300 text-emerald-700 focus:ring-emerald-500">
                    Colours-awarding only
                </label>
                @if(request()->hasAny(['search', 'year', 'series', 'colours_only']))
                    <a href="{{ route('appearances.index') }}" class="text-sm text-stone-500 hover:text-stone-700 pb-2.5">Clear</a>
                @endif
        </x-filter-bar>

        <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-stone-200 bg-stone-50/50">
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Year</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Shooter</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Division</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Championship</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Host</th>
                            <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400">Placing</th>
                            <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider text-emerald-700">Colours</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Recorded</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($appearances as $appearance)
                            <tr class="border-b border-stone-50 hover:bg-stone-50/50">
                                <td class="px-5 py-3 text-sm font-semibold text-stone-900 tabular-nums">{{ $appearance->year }}</td>
                                <td class="px-5 py-3">
                                    <a href="{{ route('shooters.show', ['saprfNumber' => $appearance->user?->membership?->saprf_number ?? $appearance->user?->id]) }}"
                                       class="text-sm font-medium text-stone-900 hover:text-emerald-700 transition">
                                        {{ $appearance->user?->name ?? '—' }}
                                    </a>
                                    @if($appearance->user?->membership?->saprf_number)
                                        <p class="text-[10px] text-stone-400 mt-0.5">SAPRF #{{ $appearance->user->membership->saprf_number }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-stone-600">{{ $appearance->divisionName() ?? '—' }}</td>
                                <td class="px-5 py-3 text-sm text-stone-600">{{ $appearance->championship_name }}</td>
                                <td class="px-5 py-3 text-sm text-stone-500">{{ $appearance->hostCountryName() ?? '—' }}</td>
                                <td class="px-5 py-3 text-center text-sm text-stone-600 tabular-nums">{{ $appearance->placing ?? '—' }}</td>
                                <td class="px-5 py-3 text-center">
                                    @if($appearance->awarded_colours)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 ring-1 ring-inset ring-emerald-200" title="This appearance granted Protea Colours">
                                            <svg class="size-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/></svg>
                                            Awarded
                                        </span>
                                    @else
                                        <span class="text-stone-300">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-stone-500 whitespace-nowrap">{{ date_short($appearance->appeared_at) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <x-row-actions>
                                        <form action="{{ route('appearances.destroy', $appearance) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <x-row-action
                                                variant="danger"
                                                title="Remove appearance"
                                                :confirm="$appearance->awarded_colours
                                                    ? 'This entry granted Protea Colours. Deleting it will auto-promote the shooter\'s earliest remaining appearance to the colours slot. Continue?'
                                                    : 'Remove this national-team appearance?'"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                            </x-row-action>
                                        </form>
                                    </x-row-actions>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-5 py-12 text-center text-sm text-stone-400">
                                    No national-team appearances recorded yet.
                                    <a href="{{ route('appearances.create') }}" class="text-emerald-700 hover:underline">Record the first one</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($appearances->hasPages())
                <div class="px-5 py-3 border-t border-stone-100">
                    {{ $appearances->links() }}
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
