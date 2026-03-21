@php
    $showProvince = $showProvince ?? true;
    $showDivision = $showDivision ?? false;
@endphp

@if($standings->isEmpty())
    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm p-12 text-center">
        <div class="inline-flex items-center justify-center size-16 rounded-2xl bg-stone-100 mb-4">
            <svg class="size-8 text-stone-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-4.5A3.375 3.375 0 0 0 13.125 10.875h-2.25A3.375 3.375 0 0 0 7.5 14.25v4.5" /></svg>
        </div>
        <h3 class="text-lg font-semibold text-stone-700">No standings data</h3>
        <p class="mt-1 text-sm text-stone-400">No ranked shooters for this division/filter combination. Standings appear after matches are completed and scored.</p>
    </div>
@else
    <div class="bg-white rounded-2xl border border-stone-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b-2 border-stone-200 bg-stone-50/50">
                        <th class="px-4 sm:px-5 py-3.5 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400 w-16">Rank</th>
                        <th class="px-4 sm:px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Shooter</th>
                        @if($showDivision)
                            <th class="px-4 sm:px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden sm:table-cell">Division</th>
                        @endif
                        @if($showProvince)
                            <th class="px-4 sm:px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden sm:table-cell">Province</th>
                        @endif
                        <th class="px-4 sm:px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Points</th>
                        <th class="px-4 sm:px-5 py-3.5 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400 w-16 hidden md:table-cell"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($standings as $standing)
                        <tr class="border-b border-stone-100 transition hover:bg-stone-50/50 {{ $standing->rank <= 3 ? 'bg-emerald-50/30' : '' }}">
                            <td class="px-4 sm:px-5 py-4 text-center">
                                @if($standing->rank === 1)
                                    <span class="inline-flex items-center justify-center size-8 rounded-full bg-amber-100 text-amber-700 text-sm font-bold shadow-sm">1</span>
                                @elseif($standing->rank === 2)
                                    <span class="inline-flex items-center justify-center size-8 rounded-full bg-stone-200 text-stone-600 text-sm font-bold">2</span>
                                @elseif($standing->rank === 3)
                                    <span class="inline-flex items-center justify-center size-8 rounded-full bg-amber-50 text-amber-600 text-sm font-bold">3</span>
                                @else
                                    <span class="text-sm font-medium text-stone-400">{{ $standing->rank }}</span>
                                @endif
                            </td>
                            <td class="px-4 sm:px-5 py-4">
                                <a href="{{ url('/standings/' . $season . '/shooter/' . ($standing->user_id ?? $standing->id)) }}"
                                   class="text-sm font-semibold text-stone-900 hover:text-emerald-700 transition">
                                    {{ $standing->user->name ?? '—' }}
                                </a>
                                @if($showDivision)
                                    <span class="sm:hidden block text-xs text-stone-400 mt-0.5">{{ $standing->division?->name ?? '—' }}</span>
                                @endif
                                @if($showProvince)
                                    @php $prov = $standing->province ?? $standing->user?->province; @endphp
                                    <span class="sm:hidden block text-xs text-stone-400 mt-0.5">{{ $prov->abbreviation ?? $prov->name ?? '—' }}</span>
                                @endif
                            </td>
                            @if($showDivision)
                                <td class="px-4 sm:px-5 py-4 hidden sm:table-cell">
                                    <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">
                                        {{ $standing->division?->name ?? '—' }}
                                    </span>
                                </td>
                            @endif
                            @if($showProvince)
                                @php $prov = $standing->province ?? $standing->user?->province; @endphp
                                <td class="px-4 sm:px-5 py-4 hidden sm:table-cell">
                                    <span class="inline-flex items-center rounded-md bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600">
                                        {{ $prov->abbreviation ?? $prov->name ?? '—' }}
                                    </span>
                                </td>
                            @endif
                            <td class="px-4 sm:px-5 py-4 text-right">
                                <span class="text-sm font-mono font-bold text-stone-900">{{ number_format($standing->points, 1) }}</span>
                            </td>
                            <td class="px-4 sm:px-5 py-4 text-center hidden md:table-cell">
                                <a href="{{ url('/standings/' . $season . '/shooter/' . ($standing->user_id ?? $standing->id)) }}"
                                   class="inline-flex items-center gap-1 text-xs text-emerald-700 font-medium hover:text-emerald-800 transition">
                                    Details
                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
