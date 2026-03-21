@php
    $showProvince = $showProvince ?? true;
    $showDivision = $showDivision ?? false;
    $colCount = 2 + ($showProvince ? 1 : 0) + ($showDivision ? 1 : 0);
@endphp

<div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
    <table class="w-full">
        <thead>
            <tr class="border-b-2 border-stone-200">
                <th class="px-5 py-3.5 text-center text-[11px] font-semibold uppercase tracking-wider text-stone-400 w-16">Rank</th>
                <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Shooter</th>
                @if($showDivision)
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden sm:table-cell">Division</th>
                @endif
                @if($showProvince)
                    <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400 hidden sm:table-cell">Province</th>
                @endif
                <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Points</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($standings as $standing)
                <tr class="border-b border-stone-100 transition hover:bg-stone-50 {{ $standing->rank <= 3 ? 'bg-emerald-50/40' : '' }}">
                    <td class="px-5 py-4 text-center">
                        @if ($standing->rank === 1)
                            <span class="inline-flex items-center justify-center size-8 rounded-full bg-amber-100 text-amber-700 text-sm font-bold">1</span>
                        @elseif ($standing->rank === 2)
                            <span class="inline-flex items-center justify-center size-8 rounded-full bg-stone-200 text-stone-600 text-sm font-bold">2</span>
                        @elseif ($standing->rank === 3)
                            <span class="inline-flex items-center justify-center size-8 rounded-full bg-amber-50 text-amber-600 text-sm font-bold">3</span>
                        @else
                            <span class="text-sm font-medium text-stone-400">{{ $standing->rank }}</span>
                        @endif
                    </td>
                    <td class="px-5 py-4">
                        <span class="text-sm font-semibold text-stone-900">{{ $standing->user->name ?? '—' }}</span>
                        @if($showDivision)
                            <span class="sm:hidden block text-xs text-stone-400 mt-0.5">{{ $standing->division?->name ?? '—' }}</span>
                        @endif
                        @if($showProvince)
                            @php $prov = $standing->province ?? $standing->user?->province; @endphp
                            <span class="sm:hidden block text-xs text-stone-400 mt-0.5">{{ $prov->abbreviation ?? $prov->name ?? '—' }}</span>
                        @endif
                    </td>
                    @if($showDivision)
                        <td class="px-5 py-4 hidden sm:table-cell">
                            <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">
                                {{ $standing->division?->name ?? '—' }}
                            </span>
                        </td>
                    @endif
                    @if($showProvince)
                        @php $prov = $standing->province ?? $standing->user?->province; @endphp
                        <td class="px-5 py-4 hidden sm:table-cell">
                            <span class="inline-flex items-center rounded-md bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600">
                                {{ $prov->abbreviation ?? $prov->name ?? '—' }}
                            </span>
                        </td>
                    @endif
                    <td class="px-5 py-4 text-right">
                        <span class="text-sm font-mono font-semibold text-stone-900">{{ number_format($standing->points, 1) }}</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $colCount + 1 }}" class="px-5 py-12 text-center text-sm text-stone-400">
                        No standings data available for this selection.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
