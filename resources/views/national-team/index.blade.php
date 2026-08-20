<x-layouts.app :title="'National Team & Protea Colours - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">National Team &amp; Protea Colours</h1>
                <p class="mt-1 text-sm text-stone-500">
                    Every year a shooter has represented South Africa. The
                    <span class="inline-flex items-center gap-1 font-semibold text-emerald-700">
                        <svg class="size-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2 8.5 8h7L12 2ZM4 10l3.5 6h9L20 10H4Zm2 8 6 4 6-4H6Z"/></svg>
                        Colours
                    </span>
                    badge marks the one-time appearance that granted the shooter their Protea Colours.
                </p>
            </div>
            <a href="{{ route('national-team.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Record Appearance
            </a>
        </div>

        @if(session('success'))
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        <div class="flex flex-wrap items-end gap-4">
            <form method="GET" action="{{ route('national-team.index') }}" class="flex items-end gap-3 flex-wrap" aria-label="Appearance filters">
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
                <button type="submit" class="rounded-lg bg-stone-100 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">Filter</button>
                @if(request()->hasAny(['search', 'year', 'series', 'colours_only']))
                    <a href="{{ route('national-team.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Clear</a>
                @endif
            </form>
        </div>

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
                                <td class="px-5 py-3 text-sm text-stone-500 whitespace-nowrap">{{ $appearance->appeared_at?->format('d M Y') }}</td>
                                <td class="px-5 py-3 text-right">
                                    <form action="{{ route('national-team.destroy', $appearance) }}" method="POST" onsubmit="return confirm('{{ $appearance->awarded_colours ? "This entry granted Protea Colours. Deleting it will auto-promote the shooter\'s earliest remaining appearance to the colours slot. Continue?" : "Remove this national-team appearance?" }}');" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-red-600 hover:text-red-800 font-medium">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-5 py-12 text-center text-sm text-stone-400">
                                    No national-team appearances recorded yet.
                                    <a href="{{ route('national-team.create') }}" class="text-emerald-700 hover:underline">Record the first one</a>.
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
