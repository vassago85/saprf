<x-layouts.app :title="'Enter Scores — ' . $match->name">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <a href="{{ url('/matches/' . $match->id) }}" class="inline-flex items-center gap-1.5 text-xs text-stone-500 hover:text-emerald-700 mb-2">
                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                Back to {{ $match->name }}
            </a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Enter Scores</h1>
            <p class="mt-1 text-sm text-stone-500">
                {{ $match->name }}
                <span class="text-stone-300 mx-1">·</span>
                {{ $match->match_date->format('D, j M Y') }}@if($isTwoDay) – {{ $match->match_end_date->format('j M Y') }}@endif
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('score-imports.create') }}?match_id={{ $match->id }}"
               class="inline-flex items-center gap-2 rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50 shadow-sm">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                Import CSV instead
            </a>
        </div>
    </div>

    @if($errors->any())
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            <ul class="list-inside list-disc space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Info card explaining the 2-day / provincial-credit logic --}}
    @if($isTwoDay)
        <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50/50 px-4 py-3 text-sm text-blue-900">
            <div class="flex items-start gap-3">
                <svg class="size-5 shrink-0 text-blue-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
                <div>
                    This is a <strong>2-day match</strong>. Enter each shooter's Day 1 and Day 2 totals separately.
                    The <strong>total (Day 1 + Day 2)</strong> is used for the national standings.
                    @if($match->also_counts_for_provincial)
                        <strong>Day 1 alone</strong> is used as the shooter's provincial-pool contribution under PR22 scoring.
                    @endif
                </div>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('scores.entry.store', $match) }}">
        @csrf

        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b-2 border-stone-200 bg-stone-50">
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-500">Shooter</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-500 hidden sm:table-cell">Division</th>
                            <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-500 w-40">
                                {{ $isTwoDay ? 'Day 1 Impacts' : 'Impacts' }}
                            </th>
                            @if($isTwoDay)
                                <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-500 w-40">Day 2 Impacts</th>
                                <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-500 w-24">Total</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100" x-data="{
                        recompute(idx) {
                            const day1 = parseFloat(this.$refs['day1_' + idx]?.value) || 0;
                            const day2 = parseFloat(this.$refs['day2_' + idx]?.value) || 0;
                            const totalEl = this.$refs['total_' + idx];
                            if (totalEl) totalEl.textContent = (day1 + day2).toFixed(1);
                        }
                    }">
                        @forelse($rows as $idx => $row)
                            @php
                                $score = $row['score'];
                                $day1Val = $score?->day1_raw_score ?? ($score && !$score->day2_raw_score ? $score->raw_score : null);
                                $day2Val = $score?->day2_raw_score;
                                $total = ($day1Val ?? 0) + ($day2Val ?? 0);
                            @endphp
                            <tr class="hover:bg-stone-50/50">
                                <td class="px-5 py-3">
                                    <div class="text-sm font-medium text-stone-900">{{ $row['name'] }}</div>
                                    <input type="hidden" name="entries[{{ $idx }}][user_id]" value="{{ $row['user_id'] }}">
                                </td>
                                <td class="px-5 py-3 hidden sm:table-cell text-xs text-stone-500">{{ $row['division'] ?? '—' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <input type="number" step="0.001" min="0"
                                        name="entries[{{ $idx }}][day1]"
                                        x-ref="day1_{{ $idx }}"
                                        @if($isTwoDay) @input="recompute({{ $idx }})" @endif
                                        value="{{ old("entries.$idx.day1", $day1Val) }}"
                                        placeholder="—"
                                        class="w-full rounded-lg border-stone-300 text-right font-mono text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                </td>
                                @if($isTwoDay)
                                    <td class="px-5 py-3 text-right">
                                        <input type="number" step="0.001" min="0"
                                            name="entries[{{ $idx }}][day2]"
                                            x-ref="day2_{{ $idx }}"
                                            @input="recompute({{ $idx }})"
                                            value="{{ old("entries.$idx.day2", $day2Val) }}"
                                            placeholder="—"
                                            class="w-full rounded-lg border-stone-300 text-right font-mono text-sm focus:border-emerald-500 focus:ring-emerald-500">
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <span class="font-mono text-sm font-bold text-emerald-700" x-ref="total_{{ $idx }}">{{ number_format($total, 1) }}</span>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isTwoDay ? 5 : 3 }}" class="px-5 py-12 text-center">
                                    <p class="text-sm text-stone-500">No registered shooters yet.</p>
                                    <p class="mt-1 text-xs text-stone-400">Shooters must register for the match before you can enter their scores.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($rows->isNotEmpty())
            <div class="mt-6 flex items-center justify-between gap-3">
                <p class="text-xs text-stone-500">
                    {{ $rows->count() }} shooter{{ $rows->count() === 1 ? '' : 's' }}.
                    Leave blank to skip a row.
                </p>
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Save Scores
                </button>
            </div>
        @endif
    </form>

    @if($orphanScores->isNotEmpty())
        <div class="mt-8 rounded-xl border border-amber-200 bg-amber-50/50 p-4">
            <div class="flex items-start gap-3">
                <svg class="size-5 text-amber-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.008v.008H12v-.008Z" /></svg>
                <div>
                    <h3 class="text-sm font-semibold text-amber-900">{{ $orphanScores->count() }} imported score{{ $orphanScores->count() === 1 ? '' : 's' }} could not be matched to a registered shooter.</h3>
                    <p class="mt-1 text-xs text-amber-800">These rows came from a CSV import but the shooter name / email / SAPRF number didn't match anyone in the system. Go to <a href="{{ route('score-imports.index') }}" class="underline">Score Imports</a> to review.</p>
                </div>
            </div>
        </div>
    @endif
</x-layouts.app>
