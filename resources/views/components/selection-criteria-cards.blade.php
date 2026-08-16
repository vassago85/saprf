@props([
    'athlete',
    'criteria',
])

@php
    use App\Services\Selection\SelectionCriteriaStatus;
    $statusBadge = fn (string $status): string => match ($status) {
        SelectionCriteriaStatus::STATUS_MET => 'bg-emerald-100 text-emerald-800',
        SelectionCriteriaStatus::STATUS_NOT_MET => 'bg-red-100 text-red-800',
        default => 'bg-amber-100 text-amber-800',
    };
    $statusLabel = fn (string $status): string => match ($status) {
        SelectionCriteriaStatus::STATUS_MET => 'Met',
        SelectionCriteriaStatus::STATUS_NOT_MET => 'Not met',
        default => 'Needs review',
    };
    $snap = $athlete?->participationSnapshot;
    $elgPct = $criteria['eligibility_total'] > 0
        ? (int) round($criteria['eligibility_met'] / $criteria['eligibility_total'] * 100)
        : 0;
    $partPct = $criteria['participation_total'] > 0
        ? (int) round($criteria['participation_met'] / $criteria['participation_total'] * 100)
        : 0;
@endphp

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-sm font-semibold text-stone-700">Eligibility (ELG)</h2>
            <span class="text-xs font-semibold text-stone-500">{{ $criteria['eligibility_met'] }} / {{ $criteria['eligibility_total'] }} met</span>
        </div>
        <div class="mb-4 h-2 w-full overflow-hidden rounded-full bg-stone-100">
            <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $elgPct }}%"></div>
        </div>
        <ul class="divide-y divide-stone-100">
            @foreach ($criteria['eligibility'] as $rule)
                <li class="py-2">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="font-mono text-xs text-stone-400">{{ $rule['code'] }}</span>
                            <span class="text-sm text-stone-700">· {{ $rule['name'] }}</span>
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusBadge($rule['status']) }}">
                            {{ $statusLabel($rule['status']) }}
                        </span>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-sm font-semibold text-stone-700">Participation (PART)</h2>
            <span class="text-xs font-semibold text-stone-500">{{ $criteria['participation_met'] }} / {{ $criteria['participation_total'] }} met</span>
        </div>
        <div class="mb-4 h-2 w-full overflow-hidden rounded-full bg-stone-100">
            <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $partPct }}%"></div>
        </div>
        @if ($snap)
            <div class="mb-4 grid grid-cols-3 gap-3 text-center">
                <div>
                    <div class="text-2xl font-bold">{{ $snap->provincial_1d_count }}<span class="text-sm font-normal text-stone-400"> / 3</span></div>
                    <div class="text-xs text-stone-500">Prov. 1-day</div>
                </div>
                <div>
                    <div class="text-2xl font-bold">{{ $snap->national_2d_count + $snap->international_2d_count }}<span class="text-sm font-normal text-stone-400"> / 2</span></div>
                    <div class="text-xs text-stone-500">2-day (nat+int'l)</div>
                </div>
                <div>
                    <div class="text-2xl font-bold">{{ $snap->out_of_home_province_2d_count }}<span class="text-sm font-normal text-stone-400"> / 1</span></div>
                    <div class="text-xs text-stone-500">Out-of-home 2-day</div>
                </div>
                <div>
                    <div class="text-2xl font-bold">{{ $snap->sa_champs_shot ? 'Yes' : 'No' }}</div>
                    <div class="text-xs text-stone-500">SA Champs</div>
                </div>
                <div class="col-span-2">
                    <div class="text-xs text-stone-500">Computed {{ $snap->computed_at?->format('Y-m-d H:i') }}</div>
                </div>
            </div>
        @else
            <p class="text-sm text-stone-500 mb-3">No snapshot yet — this fills in the first time your record is re-evaluated after you submit the form.</p>
        @endif
        <ul class="divide-y divide-stone-100">
            @foreach ($criteria['participation'] as $rule)
                <li class="py-2 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <span class="font-mono text-xs text-stone-400">{{ $rule['code'] }}</span>
                        <span class="text-sm text-stone-700">· {{ $rule['name'] }}</span>
                    </div>
                    <div class="flex shrink-0 items-center gap-1.5">
                        @if (! ($rule['boolean'] ?? false) && ($rule['required'] ?? null) !== null)
                            <span class="text-xs font-medium text-stone-500">{{ $rule['current'] }} / {{ $rule['required'] }}</span>
                        @endif
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusBadge($rule['status']) }}">
                            {{ $statusLabel($rule['status']) }}
                        </span>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</div>
