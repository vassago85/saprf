<x-layouts.app :title="'Matches'">
    <div class="flex items-center justify-between">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Matches</h1>

        @can('create', App\Models\MatchEvent::class)
            <flux:button href="{{ route('matches.create') }}" variant="primary" icon="plus">
                Create Match
            </flux:button>
        @endcan
    </div>

    <div class="mt-6 border-t border-stone-200"></div>

    <div class="mt-6 flex flex-wrap gap-3">
        <form method="GET" action="{{ route('matches.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <select name="match_type" class="rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All Types</option>
                    <option value="PRS" @selected(request('match_type') === 'PRS')>PRS</option>
                    <option value="PR22" @selected(request('match_type') === 'PR22')>PR22</option>
                </select>
            </div>

            <div>
                <select name="status" class="rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All Statuses</option>
                    <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                    <option value="open" @selected(request('status') === 'open')>Open</option>
                    <option value="closed" @selected(request('status') === 'closed')>Closed</option>
                    <option value="completed" @selected(request('status') === 'completed')>Completed</option>
                </select>
            </div>

            <flux:button type="submit" variant="ghost" icon="funnel">Filter</flux:button>
        </form>
    </div>

    <div class="mt-6 rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
        <table class="min-w-full">
            <thead class="border-b-2 border-stone-200">
                <tr>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Name</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Type</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Series Level</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Province</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                    <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($matches as $match)
                    <tr class="border-b border-stone-100 hover:bg-stone-50 transition">
                        <td class="whitespace-nowrap px-5 py-4 text-sm font-medium text-stone-900">
                            <a href="{{ route('matches.show', $match) }}" class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $match->name }}</a>
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm">
                            @if ($match->match_type === 'PRS')
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">PRS</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">PR22</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700 capitalize">{{ $match->series_level }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700">{{ $match->province?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700">
                            {{ $match->match_date->format('d M Y') }}
                            @if($match->isMultiDay())
                                <span class="text-stone-400">–</span> {{ $match->match_end_date->format('d M') }}
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm">
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
                            @endswitch
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-right text-sm">
                            <div class="flex items-center justify-end gap-2">
                                <flux:button href="{{ route('matches.show', $match) }}" variant="ghost" size="sm" icon="eye" />
                                @can('update', $match)
                                    <flux:button href="{{ route('matches.edit', $match) }}" variant="ghost" size="sm" icon="pencil-square" />
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-sm text-stone-500">No matches found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $matches->withQueryString()->links() }}
    </div>
</x-layouts.app>
