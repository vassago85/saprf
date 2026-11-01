@php
    $walkIns = $walkInRegistrations;
    $totalSaprf = round((float) $walkIns->sum('saprf_fee'), 2);
    $totalPlatform = round((float) $walkIns->sum('platform_fee'), 2);
    $totalDeduction = round($totalSaprf + $totalPlatform, 2);
@endphp

<div class="rounded-xl border border-sky-200 bg-sky-50 p-5">
    <div class="flex items-start gap-3">
        <flux:icon.hand-raised class="mt-0.5 size-6 shrink-0 text-sky-700" />
        <div class="flex-1">
            <h2 class="font-heading text-lg font-bold text-sky-950">Step 3 · Agree walk-in fees</h2>
            <p class="mt-1 text-sm text-sky-900">
                You collected the entry fee for every walk-in in cash at the range — that money stays with you.
                The platform fee and the SAPRF levy still apply, so they come off this payout.
            </p>
        </div>
    </div>
</div>

<div class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
    @if($walkIns->isEmpty())
        <div class="rounded-xl border border-dashed border-stone-200 px-4 py-6 text-center text-sm text-stone-600">
            <p class="font-semibold text-stone-900">No walk-ins on this match.</p>
            <p class="mt-1">Tick the box below so the gate opens — nothing will be deducted here.</p>
        </div>
    @else
        <table class="min-w-full divide-y divide-stone-200 text-sm">
            <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                <tr>
                    <th class="px-3 py-2 text-left font-semibold">Walk-in shooter</th>
                    <th class="px-3 py-2 text-left font-semibold">Division</th>
                    <th class="px-3 py-2 text-right font-semibold">SAPRF levy</th>
                    <th class="px-3 py-2 text-right font-semibold">Platform fee</th>
                    <th class="px-3 py-2 text-right font-semibold">Deducted</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach($walkIns as $walkIn)
                    @php
                        $saprf = (float) $walkIn->saprf_fee;
                        $platform = (float) $walkIn->platform_fee;
                        $rowTotal = round($saprf + $platform, 2);
                    @endphp
                    <tr>
                        <td class="px-3 py-2">
                            <div class="font-medium text-stone-900">
                                {{ $walkIn->shooter_name ?: ($walkIn->user?->name ?? 'Unknown') }}
                            </div>
                            @if($walkIn->email)
                                <div class="text-xs text-stone-500">{{ $walkIn->email }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-stone-700">{{ $walkIn->division?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-stone-700">R {{ number_format($saprf, 2) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-stone-700">R {{ number_format($platform, 2) }}</td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums text-stone-900">
                            − R {{ number_format($rowTotal, 2) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-stone-50">
                <tr>
                    <td colspan="2" class="px-3 py-2 text-left text-sm font-semibold text-stone-900">
                        Total coming off your payout
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums text-stone-700">R {{ number_format($totalSaprf, 2) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-stone-700">R {{ number_format($totalPlatform, 2) }}</td>
                    <td class="px-3 py-2 text-right font-bold tabular-nums text-stone-900">
                        − R {{ number_format($totalDeduction, 2) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    @endif

    @can('update', $match)
        <form method="POST" action="{{ route('matches.scores.acknowledge-walkin-fees', $match) }}"
              class="mt-6 rounded-xl border border-stone-200 bg-stone-50 p-4" x-data="{ agreed: false }">
            @csrf
            <label class="flex items-start gap-3 text-sm text-stone-900">
                <input type="checkbox" name="agreed" value="1" x-model="agreed"
                       class="mt-0.5 size-4 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
                <span>
                    I agree that the platform fee
                    @if($walkIns->isNotEmpty())
                        of <strong>R {{ number_format($totalPlatform, 2) }}</strong>
                    @endif
                    and the SAPRF levy
                    @if($walkIns->isNotEmpty())
                        of <strong>R {{ number_format($totalSaprf, 2) }}</strong>
                    @endif
                    are deducted from my match-director payout for
                    {{ $walkIns->isEmpty() ? 'any walk-ins on this match' : 'the walk-ins listed above' }}.
                </span>
            </label>
            @error('agreed')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
            <div class="mt-4">
                <button type="submit" :disabled="!agreed"
                        :class="agreed ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-stone-300 cursor-not-allowed'"
                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-semibold text-white">
                    <flux:icon.check class="size-4" />
                    Agree and continue
                </button>
            </div>
        </form>
    @else
        <p class="mt-6 text-sm text-stone-500">
            The match director needs to acknowledge these walk-in fees before the match can be closed out.
        </p>
    @endcan
</div>
