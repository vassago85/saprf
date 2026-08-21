<x-layouts.app :title="'My Barrels - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">My Barrels</h1>
                <p class="mt-1 text-sm text-stone-500">Physical barrels — round count travels with the barrel, not the rifle.</p>
            </div>
            <a href="{{ route('barrels.create') }}"
                class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add barrel
            </a>
        </div>

        @if (session('success'))
            <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        @if ($barrels->isEmpty())
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-12 text-center">
                <h3 class="text-sm font-semibold text-stone-900">No barrels yet.</h3>
                <p class="mt-1 text-sm text-stone-500">Add one to start tracking round count and ladder sessions against it.</p>
                <a href="{{ route('barrels.create') }}"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Add barrel
                </a>
            </div>
        @else
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                <table class="min-w-full divide-y divide-stone-100 text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wider text-stone-500">
                        <tr>
                            <th class="px-4 py-2 text-left">Label</th>
                            <th class="px-4 py-2 text-left">Chambering</th>
                            <th class="px-4 py-2 text-left">Maker</th>
                            <th class="px-4 py-2 text-right">Rounds</th>
                            <th class="px-4 py-2 text-left">On rifle</th>
                            <th class="px-4 py-2 text-left">Installed</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($barrels as $barrel)
                            <tr class="{{ $barrel->retired_on ? 'text-stone-400' : '' }}">
                                <td class="px-4 py-2 font-semibold text-stone-900">{{ $barrel->label }}</td>
                                <td class="px-4 py-2 text-stone-700">{{ $barrel->chambering ?: '—' }}</td>
                                <td class="px-4 py-2 text-stone-700">{{ $barrel->maker ?: '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-stone-700">{{ number_format((int) $barrel->round_count) }}</td>
                                <td class="px-4 py-2 text-stone-700">
                                    {{ $barrel->rifleConfiguration?->nickname ?: '—' }}
                                </td>
                                <td class="px-4 py-2 text-stone-500">
                                    {{ $barrel->installed_on?->format('j M Y') ?: '—' }}
                                </td>
                                <td class="px-4 py-2">
                                    @if ($barrel->retired_on)
                                        <span class="rounded bg-stone-100 px-2 py-0.5 text-xs text-stone-500">Retired</span>
                                    @else
                                        <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">Active</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('barrels.edit', $barrel) }}" class="text-xs font-medium text-emerald-700 hover:text-emerald-800">Edit</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
