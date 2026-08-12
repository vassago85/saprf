<x-layouts.app :title="$cycle->series.' '.$cycle->season">
    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-xs font-semibold uppercase tracking-widest text-stone-400">Selection Cycle</div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $cycle->series }} {{ $cycle->season }} · {{ $cycle->championship_name }}</h1>
                <p class="mt-1 text-sm text-stone-500">
                    Qualifying period {{ $cycle->qualifying_period_start?->format('Y-m-d') }} → {{ $cycle->qualifying_period_end?->format('Y-m-d') }}
                    · Declaration deadline {{ $cycle->declaration_deadline?->format('Y-m-d H:i') }}
                    · Results freeze {{ $cycle->results_freeze?->format('Y-m-d') }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                @can('update', $cycle)
                    <a href="{{ route('selection.cycles.edit', $cycle) }}" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Edit</a>
                @endcan
                @can('reevaluate', $cycle)
                    <form method="POST" action="{{ route('selection.cycles.reevaluate', $cycle) }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Re-evaluate cycle</button>
                    </form>
                @endcan
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-stone-700 mb-3">Active policy</h2>
                @if ($cycle->activePolicy)
                    <p class="text-sm text-stone-700">
                        Version <span class="font-mono font-semibold">v{{ $cycle->activePolicy->version }}</span><br>
                        Hash <span class="font-mono text-xs">{{ substr($cycle->activePolicy->source_hash, 0, 16) }}…</span><br>
                        Imported {{ $cycle->activePolicy->imported_at?->format('Y-m-d H:i') }}
                    </p>
                    <a href="{{ route('selection.cycles.policies.show', [$cycle, $cycle->activePolicy]) }}" class="mt-3 inline-flex text-sm text-emerald-700 hover:text-emerald-900">View spec →</a>
                @else
                    <p class="text-sm text-stone-500">No policy imported yet.</p>
                @endif
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-stone-700 mb-3">Import policy</h2>
                @can('importPolicy', $cycle)
                    <form method="POST" action="{{ route('selection.cycles.policies.store', $cycle) }}" enctype="multipart/form-data" class="space-y-3">
                        @csrf
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="source" value="upload" checked class="text-emerald-600"> Upload JSON
                        </label>
                        <input type="file" name="file" accept=".json,application/json" class="block w-full text-sm">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="source" value="path" class="text-emerald-600"> Import from repo path
                        </label>
                        <input type="text" name="path" placeholder="docs/selection/2027/policy.json" class="block w-full rounded-lg border border-stone-300 text-sm">
                        <button type="submit" class="w-full rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Import</button>
                    </form>
                @else
                    <p class="text-sm text-stone-500">Owner-only.</p>
                @endcan
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-stone-700 mb-3">Athletes</h2>
                <p class="text-4xl font-bold text-stone-900">{{ array_sum($athleteCounts->all()) }}</p>
                <p class="text-xs text-stone-500">registered in this cycle</p>
                <div class="mt-3 space-y-1 text-xs text-stone-600">
                    @foreach (\App\Models\SelectionAthlete::STATES as $state)
                        <div class="flex justify-between"><span>{{ str_replace('_', ' ', $state) }}</span><span>{{ $athleteCounts[$state] ?? 0 }}</span></div>
                    @endforeach
                </div>
                <a href="{{ route('selection.cycles.athletes.index', $cycle) }}" class="mt-3 inline-flex text-sm text-emerald-700 hover:text-emerald-900">Open registry →</a>
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-stone-700 mb-3">Policy history</h2>
            <table class="min-w-full text-sm">
                <thead class="bg-stone-50 text-xs uppercase text-stone-500">
                    <tr>
                        <th class="px-4 py-2 text-left">Version</th>
                        <th class="px-4 py-2 text-left">Hash</th>
                        <th class="px-4 py-2 text-left">Imported</th>
                        <th class="px-4 py-2 text-left">By</th>
                        <th class="px-4 py-2 text-left">Active</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($cycle->policies as $p)
                        <tr>
                            <td class="px-4 py-2 font-mono">v{{ $p->version }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-stone-500">{{ substr($p->source_hash, 0, 16) }}…</td>
                            <td class="px-4 py-2">{{ $p->imported_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-2">{{ optional($p->importedBy)->name ?? '—' }}</td>
                            <td class="px-4 py-2">@if ($cycle->active_policy_version_id === $p->id)<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Active</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-stone-400">No policies imported.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
