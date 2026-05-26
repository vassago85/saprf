<x-layouts.app :title="'Scores'">
    <h1 class="font-heading text-3xl font-bold text-stone-900">Scores</h1>

    <div class="mt-6 border-t border-stone-200"></div>

    <div class="mt-6 flex flex-wrap gap-3">
        <form method="GET" action="{{ route('scores.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <select name="match_id" class="rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All Matches</option>
                    @foreach ($matches as $match)
                        <option value="{{ $match->id }}" @selected(request('match_id') == $match->id)>{{ $match->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <select name="status" class="rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">All Statuses</option>
                    <option value="valid" @selected(request('status') === 'valid')>Valid</option>
                    <option value="pending" @selected(request('status') === 'pending')>Pending</option>
                    <option value="overridden" @selected(request('status') === 'overridden')>Overridden</option>
                    <option value="invalid" @selected(request('status') === 'invalid')>Invalid</option>
                </select>
            </div>

            <flux:button type="submit" variant="ghost" icon="funnel">Filter</flux:button>
        </form>
    </div>

    <div class="mt-6 rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
        <table class="min-w-full">
            <thead class="border-b-2 border-stone-200">
                <tr>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Shooter</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Division</th>
                    <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Impacts</th>
                    <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Placement</th>
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                    <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($scores as $score)
                    <tr class="border-b border-stone-100 hover:bg-stone-50 transition">
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-900">
                            <a href="{{ route('matches.show', $score->match) }}" class="text-emerald-700 hover:text-emerald-900 hover:underline">{{ $score->match->name }}</a>
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-900">{{ $score->user->name ?? $score->shooter_name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-stone-700">{{ $score->division?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-right font-mono font-medium text-stone-900">{{ $score->raw_score }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-right text-stone-700">{{ $score->placement ?? '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm">
                            @switch($score->status)
                                @case('valid')
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Valid</span>
                                    @break
                                @case('pending')
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Pending</span>
                                    @break
                                @case('overridden')
                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Overridden</span>
                                    @break
                                @case('invalid')
                                    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">Invalid</span>
                                    @break
                            @endswitch
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-right text-sm">
                            <flux:button href="{{ route('scores.show', $score) }}" variant="ghost" size="sm" icon="eye" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-sm text-stone-500">No scores found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $scores->withQueryString()->links() }}
    </div>
</x-layouts.app>
