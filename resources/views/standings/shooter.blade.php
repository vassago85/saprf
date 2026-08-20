<x-layouts.public :title="$shooter->name . ' - ' . $season . ' Rankings - SAPRF'" :description="$shooter->name . ' — ' . $season . ' SAPRF rankings, match scores, and national points on the official Precision Rifle Federation platform.'" current="standings">
    <div class="bg-stone-50 min-h-screen" x-data="{ active: '{{ $seriesOrder->first() ?? '' }}' }">
        <div class="bg-white border-b border-stone-200">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 py-8">
                <a href="{{ url('/standings?season=' . $season) }}"
                   class="inline-flex items-center gap-1.5 text-sm text-stone-500 hover:text-emerald-700 transition mb-5">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    Back to Standings
                </a>

                <div class="mb-6">
                    <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $shooter->name }}</h1>
                    <div class="flex items-center gap-3 mt-2">
                        @if($shooter->province)
                            <span class="inline-flex items-center rounded-md bg-stone-100 px-2.5 py-0.5 text-xs font-medium text-stone-600">{{ $shooter->province->name }}</span>
                        @endif
                        <span class="text-sm text-stone-500">{{ $season }} Season</span>
                    </div>
                </div>

                @include('shooters._rifle-profile', ['profileRifles' => $profileRifles])

                @include('shooters._series-tabs', [
                    'seriesOrder' => $seriesOrder,
                    'summaryBySeries' => $summaryBySeries,
                    'scoresBySeries' => $scoresBySeries,
                ])
            </div>
        </div>

        @include('shooters._season-body')
    </div>

</x-layouts.public>
