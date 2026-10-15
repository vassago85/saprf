<x-layouts.app :title="'Team Years — SAPRF'">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">
        <h1 class="font-heading text-3xl font-bold text-stone-900">Team years</h1>
        <p class="text-sm text-stone-500 mt-1">
            One row per SA team sent to an IPRF World Championship. Edit venue, dates and cover photo here.
            Add or remove individual shooters via <a href="{{ route('national-team.index') }}" class="text-emerald-700 hover:underline">National Team & Colours</a>.
        </p>

        @if($teams->isEmpty())
            <div class="mt-8 bg-white border border-stone-200 rounded-2xl p-10 text-center">
                <p class="text-stone-500">
                    No team years yet. Add appearances via
                    <a href="{{ route('national-team.create') }}" class="text-emerald-700 hover:underline">National Team &amp; Colours</a>
                    and the team record is created automatically.
                </p>
            </div>
        @else
            <div class="mt-6 grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(min(320px, 100%), 1fr));">
                @foreach($teams as $team)
                    <a href="{{ route('national-teams.edit', ['nationalTeam' => $team->id]) }}"
                       class="group block bg-white border border-stone-200 rounded-2xl overflow-hidden hover:border-emerald-600 transition">
                        <div class="h-40 bg-stone-800 relative">
                            @if($team->cover)
                                <x-media-picture :item="$team->cover" sizes="320px" class="h-40"
                                                 imgClass="w-full h-40 object-cover" />
                            @else
                                <div class="w-full h-40 bg-gradient-to-br from-stone-700 to-stone-900 flex items-center justify-center text-stone-500 text-xs uppercase tracking-wider">
                                    No cover
                                </div>
                            @endif
                        </div>
                        <div class="p-5">
                            <div class="font-heading font-extrabold text-3xl text-emerald-700 leading-none">{{ $team->year }}</div>
                            <div class="font-semibold text-stone-900 mt-2">{{ $team->championship_name }}</div>
                            <div class="text-xs text-stone-500 mt-1">
                                {{ ucfirst($team->discipline) }} · {{ $team->appearances_count }} {{ Str::plural('appearance', $team->appearances_count) }}
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
