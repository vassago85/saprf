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
                        <dt class="text-stone-400">Action</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->action_description ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-400">Barrel</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->barrel_description ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-400">Chassis / Stock</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->chassis_description ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-400">Optic Brand</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->opticMake?->name ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-400">Optic Model</dt>
                        <dd class="font-medium text-stone-900">{{ $rifleConfiguration->opticModel?->name ?: '—' }}</dd>
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
                        <p class="text-2xl font-bold text-stone-900">{{ number_format($rifleConfiguration->total_barrel_rounds) }}</p>
                        <p class="mt-0.5 text-xs text-stone-500">Total Rounds</p>
                    </div>
                    <div class="rounded-lg bg-stone-50 p-4 text-center">
                        <p class="text-2xl font-bold text-stone-400">—</p>
                        <p class="mt-0.5 text-xs text-stone-500">Best Finish</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Shot Log --}}
        @if($shotLog->isNotEmpty())
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-stone-100 px-5 py-3 bg-stone-50">
                <h2 class="font-heading text-base font-bold text-stone-900">Shot Log</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-stone-200">
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Match</th>
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Date</th>
                            <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Rounds</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($shotLog as $entry)
                            <tr class="hover:bg-stone-50 transition-colors">
                                <td class="whitespace-nowrap px-5 py-3 text-sm font-medium text-stone-900">{{ $entry->match->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-stone-500">{{ $entry->match->match_date?->format('d M Y') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm font-mono text-stone-700 text-right">{{ number_format($entry->shot_count) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-stone-300 bg-stone-50">
                            <td colspan="2" class="px-5 py-3 text-sm font-semibold text-stone-700">Total</td>
                            <td class="px-5 py-3 text-sm font-mono font-semibold text-stone-900 text-right">{{ number_format($rifleConfiguration->total_barrel_rounds) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        @endif

        {{-- Ammo Loads --}}
        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-stone-100 px-5 py-3 bg-stone-50 flex items-center justify-between">
                <h2 class="font-heading text-base font-bold text-stone-900">Ammo Loads</h2>
                <a href="{{ route('ammo-loads.create', $rifleConfiguration) }}"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800 transition">
                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Add Load
                </a>
            </div>

            @if ($rifleConfiguration->ammoLoads->isNotEmpty())
                <div class="divide-y divide-stone-100">
                    @foreach ($rifleConfiguration->ammoLoads as $load)
                        <div class="px-5 py-4 flex items-center gap-4 hover:bg-stone-50/50 transition-colors">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-stone-900">{{ $load->nickname }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-stone-500">
                                    @if ($load->bullet_make || $load->bullet_weight || $load->bullet_type)
                                        <span class="font-medium text-stone-700">
                                            {{ collect([$load->bullet_make, $load->bullet_weight, $load->bullet_model ?: $load->bullet_type])->filter()->implode(' ') }}
                                        </span>
                                    @endif
                                    @if ($load->powder)
                                        <span>{{ $load->powder }}@if ($load->charge_weight) / {{ $load->charge_weight }}@endif</span>
                                    @endif
                                    @if ($load->velocity)
                                        <span>{{ $load->velocity }}</span>
                                    @endif
                                    @if ($load->brass)
                                        <span>{{ $load->brass }} brass</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <a href="{{ route('ammo-loads.edit', $load) }}"
                                    class="text-xs font-medium text-emerald-700 hover:text-emerald-800 transition">Edit</a>
                                <form method="POST" action="{{ route('ammo-loads.destroy', $load) }}" onsubmit="return confirm('Remove this ammo load?')" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-medium text-red-500 hover:text-red-700 transition">Remove</button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="px-5 py-10 text-center">
                    <p class="text-sm text-stone-400">No ammo loads for this rifle yet.</p>
                    <a href="{{ route('ammo-loads.create', $rifleConfiguration) }}"
                        class="mt-2 inline-flex text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                        Add your first load &rarr;
                    </a>
                </div>
            @endif
        </div>

        {{-- Recent Scores --}}
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
                            <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Impacts</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($recentScores as $score)
                            <tr class="hover:bg-stone-50 transition-colors">
                                <td class="whitespace-nowrap px-5 py-3 text-sm font-medium text-stone-900">{{ $score->match->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-stone-500">{{ $score->match->match_date?->format('d M Y') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-stone-500">{{ $score->placement ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-sm font-mono text-stone-700">{{ $score->raw_score ?? '—' }}</td>
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
