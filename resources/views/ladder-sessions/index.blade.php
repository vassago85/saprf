<x-layouts.app :title="'Ladder Analyser - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Ladder Analyser</h1>
                <p class="mt-1 text-sm text-stone-500">Fire the ladder, paste the chrono, see what the data actually supports.</p>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('ladder-sessions.store') }}"
              class="rounded-xl border border-stone-200 bg-white shadow-sm p-5 space-y-4 max-w-2xl">
            @csrf
            <p class="text-sm font-semibold text-stone-900">Start a new ladder</p>
            <div class="grid sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-stone-500 mb-1">Name</label>
                    <input type="text" name="name" required maxlength="120" placeholder="e.g. 6mm Dasher 105 Hyb H4350"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Variable</label>
                    <select name="variable" class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="charge_weight">Charge weight (gr)</option>
                        <option value="seating_depth">Seating depth (mm)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Fired on</label>
                    <input type="date" name="fired_on" required value="{{ now()->toDateString() }}"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
            </div>
            @if ($errors->any())
                <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                    @foreach ($errors->all() as $err) <li>{{ $err }}</li> @endforeach
                </ul>
            @endif
            <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                Create
            </button>
        </form>

        @if ($sessions->isEmpty())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-12 text-center">
                <h3 class="text-sm font-semibold text-stone-900">No ladders yet.</h3>
                <p class="mt-1 text-sm text-stone-500">Create one above.</p>
            </div>
        @else
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                <table class="min-w-full divide-y divide-stone-100 text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wider text-stone-500">
                        <tr>
                            <th class="px-4 py-2 text-left">Name</th>
                            <th class="px-4 py-2 text-left">Variable</th>
                            <th class="px-4 py-2 text-left">Fired</th>
                            <th class="px-4 py-2 text-left">Barrel</th>
                            <th class="px-4 py-2 text-left">Ammo load</th>
                            <th class="px-4 py-2 text-right">Steps</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($sessions as $s)
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ route('ladder-sessions.show', $s) }}" class="font-semibold text-stone-900 hover:text-emerald-700">{{ $s->name }}</a>
                                </td>
                                <td class="px-4 py-2 text-stone-700">{{ $s->variableEnum()->label() }}</td>
                                <td class="px-4 py-2 text-stone-500">{{ optional($s->fired_on)->format('j M Y') }}</td>
                                <td class="px-4 py-2 text-stone-700">{{ $s->barrel?->label ?: '—' }}</td>
                                <td class="px-4 py-2 text-stone-700">{{ $s->ammoLoad?->nickname ?: '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-stone-700">{{ $s->steps_count }}</td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('ladder-sessions.show', $s) }}" class="text-xs font-medium text-emerald-700 hover:text-emerald-800">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
