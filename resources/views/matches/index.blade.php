<x-layouts.app :title="'Matches'">
    <x-page-header title="Manage Matches">
        <x-slot:actions>
            @can('create', App\Models\MatchEvent::class)
                <flux:button href="{{ route('matches.create') }}" variant="primary" icon="plus">
                    Create Match
                </flux:button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <h2 class="sr-only">Filters</h2>
    <x-filter-bar :action="route('matches.index')" label="Match filters">
            <div>
                <label for="matches_match_type" class="block text-xs font-medium text-stone-500 mb-1">Type</label>
                <select id="matches_match_type" name="match_type" class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All Types</option>
                    <option value="PRS" @selected(request('match_type') === 'PRS')>PRS</option>
                    <option value="PR22" @selected(request('match_type') === 'PR22')>PR22</option>
                </select>
            </div>

            <div>
                <label for="matches_status" class="block text-xs font-medium text-stone-500 mb-1">Status</label>
                <select id="matches_status" name="status" class="rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All Statuses</option>
                    <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                    <option value="open" @selected(request('status') === 'open')>Open</option>
                    <option value="closed" @selected(request('status') === 'closed')>Closed</option>
                    <option value="completed" @selected(request('status') === 'completed')>Completed</option>
                    <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
                </select>
            </div>

    </x-filter-bar>

    <h2 class="sr-only">Matches list</h2>

    {{--
        Responsive columns: Name / Type / Province / Date / Status are
        always visible. Series Level and Venue collapse below `md`;
        MD column collapses below `lg`. The Match name links straight
        to the workspace where the full detail is visible.
    --}}
    <x-admin-table>
            <table class="min-w-full">
            <thead class="border-b-2 border-stone-200">
                <tr>
                    <th class="px-3 md:px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Name</th>
                    <th class="px-3 md:px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Type</th>
                    <th class="hidden md:table-cell px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Series Level</th>
                    <th class="px-3 md:px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Province</th>
                    <th class="hidden md:table-cell px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Venue</th>
                    <th class="hidden lg:table-cell px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">MD</th>
                    <th class="px-3 md:px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</th>
                    <th class="px-3 md:px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                </tr>
            </thead>
            <tbody>
                {{-- Each row is a single link into the match workspace.
                     Dropped the eye + pencil icon buttons on the right: the
                     workspace tabs are the one place to view AND edit, so
                     the duplicate affordances just created confusion. --}}
                @forelse ($matches as $match)
                    <tr class="cursor-pointer border-b border-stone-100 transition hover:bg-stone-50"
                        onclick="window.location.assign('{{ route('matches.overview', $match) }}')">
                        <td class="px-3 md:px-5 py-4 text-sm font-medium text-stone-900">
                            <a href="{{ route('matches.overview', $match) }}"
                               wire:navigate
                               onclick="event.stopPropagation()"
                               class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $match->name }}</a>
                            {{-- Mobile-only: surface Province abbreviation under the match name since that column is hidden until md. --}}
                            <span class="md:hidden mt-0.5 block text-[11px] text-stone-400">{{ $match->province?->abbreviation ?? '' }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 md:px-5 py-4 text-sm">
                            @if ($match->match_type === 'PRS')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">PRS</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">PR22</span>
                            @endif
                        </td>
                        <td class="hidden md:table-cell whitespace-nowrap px-5 py-4 text-sm text-stone-700 capitalize">{{ $match->series_level }}</td>
                        <td class="whitespace-nowrap px-3 md:px-5 py-4 text-sm text-stone-700">
                            <span class="hidden md:inline">{{ $match->province?->name ?? '—' }}</span>
                            <span class="md:hidden">{{ $match->province?->abbreviation ?? '—' }}</span>
                        </td>
                        <td class="hidden md:table-cell max-w-[220px] truncate px-5 py-4 text-sm text-stone-700" title="{{ $match->venue_name }}">{{ $match->venue_name ?: '—' }}</td>
                        <td class="hidden lg:table-cell whitespace-nowrap px-5 py-4 text-sm text-stone-700">{{ $match->director_name ?: '—' }}</td>
                        <td class="whitespace-nowrap px-3 md:px-5 py-4 text-sm text-stone-700">
                            {{-- Compact date on narrow screens, full on md+. --}}
                            <span class="md:hidden">{{ $match->match_date->format('d M') }}</span>
                            <span class="hidden md:inline">{{ $match->match_date->format('d M Y') }}</span>
                            @if($match->isMultiDay())
                                <span class="text-stone-400">–</span> {{ $match->match_end_date->format('d M') }}
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-3 md:px-5 py-4 text-sm">
                            @switch($match->status)
                                @case('draft')
                                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">Draft</span>
                                    @break
                                @case('open')
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Open</span>
                                    @break
                                @case('closed')
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Closed</span>
                                    @break
                                @case('completed')
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Completed</span>
                                    @break
                                @case('cancelled')
                                    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Cancelled</span>
                                    @break
                            @endswitch
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-12 text-center text-sm text-stone-500">No matches found.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>
    </x-admin-table>

    <div class="mt-6">
        {{ $matches->withQueryString()->links() }}
    </div>
</x-layouts.app>
