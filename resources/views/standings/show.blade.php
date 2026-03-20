<x-layouts.app :title="$series . ' Standings ' . $season . ' - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $series }} Standings</h1>
                <p class="mt-1 text-sm text-stone-500">Season {{ $season }} — Full rankings</p>
            </div>
            <a href="{{ route('standings.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">
                ← Back to Standings
            </a>
        </div>

        @include('standings._table', ['standings' => $standings, 'showProvince' => true])
    </div>
</x-layouts.app>
