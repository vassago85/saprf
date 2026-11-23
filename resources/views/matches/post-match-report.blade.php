<x-layouts.app :title="'Post-match report · '.$match->name">
    @include('matches.workspace._chrome', ['match' => $match, 'tabs' => [], 'activeTab' => 'post-match-report', 'counts' => [], 'stageContext' => $match->stageContext()])

    <div class="mt-6 space-y-6">
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Championship report · {{ $series }} {{ $season }}</p>
                    <h1 class="mt-1 font-heading text-2xl font-bold text-amber-950">{{ $match->name }}</h1>
                    <p class="mt-1 text-xs text-amber-900">
                        Top 5 in each division, year totals and the nationals that count on the log.
                        Only shooters who shot this championship are listed.
                    </p>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <a
                        href="{{ route('matches.post-match-report.pdf', $match) }}"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-amber-900 px-3 py-1.5 text-xs font-semibold text-amber-50 shadow-sm hover:bg-amber-950"
                    >
                        <flux:icon name="arrow-down-tray" class="size-3.5" />
                        Download PDF
                    </a>
                    <p class="text-xs text-amber-800">Generated {{ $generated_at->format('D j M Y, H:i') }}</p>
                </div>
            </div>
        </div>

        @if(! $has_standings)
            <div class="rounded-xl border border-stone-200 bg-white p-6 text-sm text-stone-600">
                No year standings yet for {{ $series }} {{ $season }}. The report needs the standings to be rebuilt after the championship scores are loaded.
            </div>
        @endif

        @foreach($divisions as $block)
            @php
                $division = $block['division'];
                $winner = $block['winner'];
                $yearMax = $block['year_max'];
            @endphp
            <section class="rounded-2xl border border-stone-200 bg-white shadow-sm">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 px-6 py-4">
                    <div>
                        <h2 class="font-heading text-lg font-bold text-stone-900">{{ $division->name }}</h2>
                        <p class="mt-0.5 text-xs text-stone-500">
                            {{ $block['shooters_total'] }} {{ \Illuminate\Support\Str::plural('shooter', $block['shooters_total']) }} from this division at champs
                        </p>
                    </div>
                    @if($winner)
                        <div class="text-right">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">{{ $season }} Division Winner</p>
                            <p class="mt-0.5 font-heading text-base font-bold text-stone-900">{{ $winner['user']?->name ?? '—' }}</p>
                            <p class="text-xs text-stone-500">
                                {{ number_format($winner['year_points'], 1) }}
                                @if($winner['year_max']) / {{ $winner['year_max'] }} @endif
                                &middot; +{{ number_format($winner['today_points'], 1) }} from today
                            </p>
                        </div>
                    @endif
                </header>

                @if($block['top5'] === [])
                    <div class="px-6 py-5 text-sm text-stone-500">
                        No ranked shooters from this division shot the championship.
                    </div>
                @else
                    <div class="divide-y divide-stone-100">
                        @foreach($block['top5'] as $row)
                            <div class="px-6 py-4">
                                <div class="flex flex-wrap items-baseline justify-between gap-3">
                                    <div class="flex items-baseline gap-3 min-w-0">
                                        <span class="inline-flex size-9 items-center justify-center rounded-full bg-stone-900 font-heading text-sm font-bold text-white tabular-nums">
                                            {{ $row['rank_label'] ?? $row['rank'] }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="font-heading text-base font-semibold text-stone-900 truncate">
                                                {{ $row['user']?->name ?? '—' }}
                                            </p>
                                            <p class="text-xs text-stone-500">
                                                Today + {{ number_format($row['today_points'], 1) }}
                                                @if($row['today_match_pct'] !== null)
                                                    ({{ number_format($row['today_match_pct'], 2) }}%)
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-mono text-lg font-bold text-stone-900 tabular-nums">
                                            {{ number_format($row['year_points'], 1) }}
                                            @if($row['year_max'])
                                                <span class="text-xs font-semibold text-stone-400">/ {{ $row['year_max'] }}</span>
                                            @endif
                                        </p>
                                        <p class="text-[11px] text-stone-500">Year total</p>
                                    </div>
                                </div>

                                <div class="mt-3 rounded-lg border border-stone-100 bg-stone-50 px-4 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-500">
                                        Counted nationals ({{ count($row['counted_regulars']) }} of best {{ $row['regular_best_of'] ?: count($row['counted_regulars']) }} · shot {{ $row['nationals_shot'] }})
                                    </p>
                                    @if($row['counted_regulars'] === [])
                                        <p class="mt-2 text-xs text-stone-500">No counting nationals yet — the championship is their only logged match.</p>
                                    @else
                                        <ul class="mt-2 space-y-1 text-sm">
                                            @foreach($row['counted_regulars'] as $reg)
                                                <li class="flex items-center justify-between gap-3">
                                                    <span class="truncate">
                                                        {{ $reg['match_name'] }}
                                                        @if($reg['level'] === 'international')
                                                            <span class="ml-1 text-[10px] font-semibold uppercase text-sky-700">Intl</span>
                                                        @elseif($reg['home'])
                                                            <span class="ml-1 text-[10px] font-semibold uppercase text-stone-500">Home</span>
                                                        @endif
                                                    </span>
                                                    <span class="font-mono tabular-nums text-stone-800">{{ number_format($reg['pct'], 2) }}%</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        <p class="mt-2 text-[11px] text-stone-500">Regular subtotal {{ number_format($row['regular_total'], 2) }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        @if($divisions === [])
            <div class="rounded-xl border border-stone-200 bg-white p-6 text-sm text-stone-600">
                No division scores on this championship yet.
            </div>
        @endif
    </div>
</x-layouts.app>
