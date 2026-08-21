<x-layouts.app :title="$barrel->label . ' - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <a href="{{ route('barrels.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; My Barrels</a>
                <div class="mt-1 flex items-center gap-3">
                    <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $barrel->label }}</h1>
                    @if ($barrel->retired_on)
                        <span class="rounded bg-stone-100 px-2 py-0.5 text-xs text-stone-500">Retired</span>
                    @else
                        <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">Active</span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-stone-500">
                    {{ collect([$barrel->maker, $barrel->chambering, $barrel->twist_rate])->filter()->implode(' · ') ?: 'No build details yet' }}
                </p>
            </div>
            <a href="{{ route('barrels.edit', $barrel) }}"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                Edit barrel
            </a>
        </div>

        @if (session('success'))
            <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <p class="text-xs uppercase tracking-wider text-stone-400">Lifetime rounds</p>
                <p class="mt-2 text-3xl font-bold text-stone-900 tabular-nums">{{ number_format($barrel->round_count) }}</p>
                <p class="mt-1 text-xs text-stone-500">
                    Starting {{ number_format($barrel->starting_round_count) }}
                    + logged {{ number_format($barrel->round_count - $barrel->starting_round_count) }}
                </p>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <p class="text-xs uppercase tracking-wider text-stone-400">On rifle</p>
                <p class="mt-2 text-lg font-semibold text-stone-900">
                    {{ $barrel->rifleConfiguration?->nickname ?: '—' }}
                </p>
                @if ($barrel->installed_on)
                    <p class="mt-1 text-xs text-stone-500">Installed {{ $barrel->installed_on->format('j M Y') }}</p>
                @endif
            </div>

            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <p class="text-xs uppercase tracking-wider text-stone-400">Logged entries</p>
                <p class="mt-2 text-3xl font-bold text-stone-900 tabular-nums">{{ $shotEntries->count() }}</p>
                <p class="mt-1 text-xs text-stone-500">Practice + non-SAPRF events</p>
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            <h2 class="font-heading text-lg font-bold text-stone-900">Add rounds</h2>
            <p class="mt-1 text-sm text-stone-500">Log a practice session or a non-SAPRF event.</p>

            @if ($errors->any())
                <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4">
                    <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('barrels.shot-entries.store', $barrel) }}" class="mt-4 grid gap-4 sm:grid-cols-4">
                @csrf
                <div>
                    <label for="fired_on" class="block text-xs font-medium text-stone-700 mb-1">Date</label>
                    <input type="date" name="fired_on" id="fired_on" required
                           max="{{ now()->toDateString() }}"
                           value="{{ old('fired_on', now()->toDateString()) }}"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label for="shot_count" class="block text-xs font-medium text-stone-700 mb-1">Rounds</label>
                    <input type="number" name="shot_count" id="shot_count" required min="1" max="9999"
                           value="{{ old('shot_count') }}"
                           placeholder="e.g. 40"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label for="type" class="block text-xs font-medium text-stone-700 mb-1">Type</label>
                    <select name="type" id="type" required
                            class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="practice" @selected(old('type') === 'practice')>Practice</option>
                        <option value="non_saprf" @selected(old('type') === 'non_saprf')>Non-SAPRF event</option>
                    </select>
                </div>
                <div>
                    <label for="notes" class="block text-xs font-medium text-stone-700 mb-1">Notes (optional)</label>
                    <input type="text" name="notes" id="notes" maxlength="500"
                           value="{{ old('notes') }}"
                           placeholder="e.g. Zero + drills, 300 m plates"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div class="sm:col-span-4">
                    <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                        Log rounds
                    </button>
                </div>
            </form>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-stone-100 px-5 py-3 bg-stone-50">
                <h2 class="font-heading text-base font-bold text-stone-900">Shot log</h2>
            </div>

            @if ($shotEntries->isEmpty())
                <div class="px-5 py-10 text-center text-sm text-stone-500">
                    No practice or non-SAPRF entries yet. Add your first above.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-stone-200 bg-stone-50/60">
                                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</th>
                                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Type</th>
                                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Notes</th>
                                <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Rounds</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($shotEntries as $entry)
                                <tr class="hover:bg-stone-50/60">
                                    <td class="px-5 py-3 text-sm text-stone-700 whitespace-nowrap">{{ $entry->fired_on->format('j M Y') }}</td>
                                    <td class="px-5 py-3 text-sm">
                                        @if ($entry->type === \App\Models\BarrelShotEntry::TYPE_PRACTICE)
                                            <span class="rounded bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-700">Practice</span>
                                        @else
                                            <span class="rounded bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-600/20">Non-SAPRF</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-sm text-stone-500">{{ $entry->notes ?: '—' }}</td>
                                    <td class="px-5 py-3 text-sm font-mono text-stone-800 text-right tabular-nums">{{ number_format($entry->shot_count) }}</td>
                                    <td class="px-5 py-3 text-right">
                                        <form method="POST" action="{{ route('barrels.shot-entries.destroy', [$barrel, $entry]) }}"
                                              onsubmit="return confirm('Remove this entry? Lifetime rounds will be recalculated.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-medium text-red-700 hover:text-red-800">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-stone-300 bg-stone-50">
                                <td colspan="3" class="px-5 py-3 text-sm font-semibold text-stone-700">
                                    Total logged
                                </td>
                                <td class="px-5 py-3 text-sm font-mono font-semibold text-stone-900 text-right tabular-nums">
                                    {{ number_format($shotEntries->sum('shot_count')) }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
