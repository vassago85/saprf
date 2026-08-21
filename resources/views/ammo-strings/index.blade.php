<x-layouts.app :title="'String Analyser - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">String Analyser</h1>
                <p class="mt-1 text-sm text-stone-500">One load, N shots, in fire order. Confirm the SD you thought you had.</p>
            </div>
        </div>

        @if (session('success'))
            <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('ammo-strings.store') }}"
              class="rounded-xl border border-stone-200 bg-white shadow-sm p-5 space-y-4 max-w-2xl">
            @csrf
            <p class="text-sm font-semibold text-stone-900">Start a new string</p>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Label</label>
                    <input type="text" name="label" required maxlength="120" placeholder="e.g. 6mm Dasher · H4350 40.8 gr · 25-round confirmation"
                           class="w-full rounded-lg border border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
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

        @if ($strings->isEmpty())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-12 text-center">
                <h3 class="text-sm font-semibold text-stone-900">No confirmation strings yet.</h3>
                <p class="mt-1 text-sm text-stone-500">Create one above, then paste the chronograph velocities in fire order.</p>
            </div>
        @else
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                <table class="min-w-full divide-y divide-stone-100 text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wider text-stone-500">
                        <tr>
                            <th class="px-4 py-2 text-left">Label</th>
                            <th class="px-4 py-2 text-left">Fired</th>
                            <th class="px-4 py-2 text-left">Ammo load</th>
                            <th class="px-4 py-2 text-left">Barrel</th>
                            <th class="px-4 py-2 text-right">Shots</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($strings as $s)
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ route('ammo-strings.show', $s) }}" class="font-semibold text-stone-900 hover:text-emerald-700">{{ $s->label }}</a>
                                </td>
                                <td class="px-4 py-2 text-stone-500">{{ optional($s->fired_on)->format('j M Y') }}</td>
                                <td class="px-4 py-2 text-stone-700">{{ $s->ammoLoad?->displayName() ?: '—' }}</td>
                                <td class="px-4 py-2 text-stone-700">{{ $s->barrel?->label ?: '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-stone-700">{{ $s->shots_count }}</td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('ammo-strings.show', $s) }}" class="text-xs font-medium text-emerald-700 hover:text-emerald-800">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
