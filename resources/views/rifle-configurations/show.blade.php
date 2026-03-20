<x-layouts.app :title="$rifleConfiguration->nickname . ' - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <a href="{{ route('rifle-configurations.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; My Rifles</a>
                <div class="mt-1 flex items-center gap-3">
                    <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $rifleConfiguration->nickname }}</h1>
                    @if ($rifleConfiguration->is_primary)
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Primary</span>
                    @endif
                </div>
            </div>
            <a href="{{ route('rifle-configurations.edit', $rifleConfiguration) }}"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                Edit Rifle
            </a>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-4">
                <h2 class="font-heading text-lg font-bold text-stone-900">Build Details</h2>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-stone-400">Make</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->make->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-400">Model</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->model->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-400">Calibre</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->calibre->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-400">Optic</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->optic_description ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-400">Bullet</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->bullet_description ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-400">Barrel Length</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->barrel_length ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-400">Twist Rate</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->twist_rate ?: '—' }}</dd>
                    </div>
                </dl>
                @if ($rifleConfiguration->notes)
                    <div class="border-t border-stone-100 pt-3">
                        <dt class="text-xs text-stone-400 mb-1">Notes</dt>
                        <dd class="text-sm text-stone-700">{{ $rifleConfiguration->notes }}</dd>
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="font-heading text-lg font-bold text-stone-900">Performance</h2>
                <div class="mt-4 grid grid-cols-3 gap-4">
                    <div class="rounded-lg bg-stone-50 p-4 text-center">
                        <p class="text-2xl font-bold text-stone-900">{{ $matchCount ?? 0 }}</p>
                        <p class="mt-0.5 text-xs text-stone-500">Total Matches</p>
                    </div>
                    <div class="rounded-lg bg-stone-50 p-4 text-center">
                        <p class="text-2xl font-bold text-stone-400">—</p>
                        <p class="mt-0.5 text-xs text-stone-500">Avg Placement</p>
                    </div>
                    <div class="rounded-lg bg-stone-50 p-4 text-center">
                        <p class="text-2xl font-bold text-stone-400">—</p>
                        <p class="mt-0.5 text-xs text-stone-500">Best Finish</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-stone-100 px-5 py-3 bg-stone-50">
                <h2 class="font-heading text-base font-bold text-stone-900">Recent Scores</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-stone-200">
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Placement</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Score</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($recentScores as $score)
                            <tr class="hover:bg-stone-50 transition-colors">
                                <td class="whitespace-nowrap px-5 py-3 text-sm font-medium text-stone-900">{{ $score->match->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-stone-500">{{ $score->match->match_date?->format('d M Y') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-stone-500">{{ $score->placement ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm font-mono text-stone-700">{{ $score->total_score ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-12 text-center text-sm text-stone-400">No scores recorded with this rifle yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
