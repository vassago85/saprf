{{--
    PRS / PR22 series selector. Each tab is a card showing that series'
    top-line season standings (national + provincial for PR22) and setting
    Alpine `active` state that the season-body partial reacts to.

    Depends on the parent view providing `x-data="{ active: ... }"`.
--}}
@if($seriesOrder->isNotEmpty())
    <div class="grid grid-cols-1 @if($seriesOrder->count() >= 2) sm:grid-cols-2 @endif gap-3">
        @foreach($seriesOrder as $series)
            @php
                $tabEntry = $summaryBySeries[$series] ?? null;
                $tabMatches = ($scoresBySeries[$series] ?? collect())->count();
                // Explicit class strings (not interpolated) so
                // the Tailwind JIT sees both variants as
                // literal tokens in the source and includes
                // them in the build.
                $activeClasses = $series === 'PRS'
                    ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-400 shadow-sm'
                    : 'border-sky-500 bg-sky-50 ring-2 ring-sky-400 shadow-sm';
                $headingClass = $series === 'PRS' ? 'text-emerald-700' : 'text-sky-700';
            @endphp
            <button type="button"
                    @click="active = '{{ $series }}'"
                    :class="active === '{{ $series }}'
                        ? '{{ $activeClasses }}'
                        : 'border-stone-200 bg-white hover:border-stone-300'"
                    class="rounded-2xl border-2 p-5 text-left transition cursor-pointer">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-bold uppercase tracking-wider {{ $headingClass }}">{{ $series }} Standings</span>
                        <x-discipline-chip :discipline="$series" />
                    </div>
                    <span x-show="active === '{{ $series }}'" x-cloak class="text-[10px] font-semibold uppercase tracking-wider {{ $headingClass }} flex items-center gap-1">
                        <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        Viewing
                    </span>
                    <span x-show="active !== '{{ $series }}'" class="text-[10px] font-semibold uppercase tracking-wider text-stone-400">Click to view</span>
                </div>

                @if($tabEntry && $tabEntry['overall_rank'] !== null)
                    <div class="flex items-start gap-6 flex-wrap">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-emerald-600">National</p>
                            <div class="flex items-baseline gap-2">
                                <p class="text-3xl font-bold text-stone-900">#{{ $tabEntry['overall_rank'] }}</p>
                                <p class="text-sm text-stone-500 tabular-nums">{{ number_format($tabEntry['overall_points'] ?? 0, 2) }} pts</p>
                            </div>
                            {{-- One chip per division the shooter competed in. A single shooter
                                 may have several (e.g. Open in one match, Factory in another) and
                                 each is ranked independently. --}}
                            @if(!empty($tabEntry['divisions']))
                                <div class="mt-0.5 flex flex-wrap gap-x-2 gap-y-0 text-[10px] text-stone-400">
                                    @foreach($tabEntry['divisions'] as $div)
                                        <span>{{ $div['name'] }}: <span class="font-bold text-amber-700">#{{ $div['rank'] ?? '—' }}</span></span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        @if(!empty($tabEntry['has_provincial']))
                            <div class="border-l border-stone-200 pl-6">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-600">
                                    Provincial @if($tabEntry['province_name'])<span class="text-blue-400">&middot; {{ $tabEntry['province_name'] }}</span>@endif
                                </p>
                                <div class="flex items-baseline gap-2">
                                    <p class="text-3xl font-bold text-stone-900">#{{ $tabEntry['provincial_rank'] ?? '—' }}</p>
                                    <p class="text-sm text-stone-500 tabular-nums">{{ number_format($tabEntry['provincial_points'] ?? 0, 2) }} pts</p>
                                </div>
                                @if(!empty($tabEntry['provincial_divisions']))
                                    <div class="mt-0.5 flex flex-wrap gap-x-2 gap-y-0 text-[10px] text-stone-400">
                                        @foreach($tabEntry['provincial_divisions'] as $div)
                                            <span>{{ $div['name'] }}: <span class="font-bold text-amber-700">#{{ $div['rank'] ?? '—' }}</span></span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-stone-500">
                        {{ $tabMatches }} match{{ $tabMatches === 1 ? '' : 'es' }} attended <span class="text-stone-400">&mdash; not ranked</span>
                    </p>
                @endif
            </button>
        @endforeach
    </div>
@endif
