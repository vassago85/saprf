<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SAPRF Championship Report — {{ $series }} {{ $season }}</title>
    <style>
        @page {
            margin: 24px 24px 32px 24px;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1c1917;
            margin: 0;
            line-height: 1.4;
        }

        /* ───── Header strip ───── */
        .header {
            border-bottom: 3px solid #047857;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 2px;
            color: #047857;
            letter-spacing: -0.3px;
        }
        .header .sub {
            font-size: 10px;
            color: #44403c;
            margin: 0;
            font-weight: 500;
        }
        .header .meta {
            font-size: 8px;
            color: #78716c;
            margin: 6px 0 0;
        }

        /* ───── Caption strip ───── */
        .caption {
            font-size: 9px;
            color: #57534e;
            margin: 0 0 14px;
            padding: 6px 10px;
            background: #fafaf9;
            border-left: 3px solid #047857;
        }

        /* ───── Division block ───── */
        .division {
            page-break-inside: auto;
            margin-bottom: 14px;
        }
        .division h2 {
            font-size: 13px;
            font-weight: 700;
            margin: 10px 0 6px;
            padding: 5px 10px;
            background: #047857;
            color: #ffffff;
            border-radius: 3px;
        }
        .division .div-meta {
            float: right;
            font-size: 8px;
            color: #ecfdf5;
            font-weight: 500;
            text-transform: none;
            letter-spacing: 0;
        }

        /* ───── Winner callout ───── */
        .winner {
            page-break-inside: avoid;
            margin: 0 0 6px;
            padding: 6px 10px;
            background: #ecfdf5;
            border-left: 3px solid #047857;
        }
        .winner .label {
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #047857;
            margin: 0;
        }
        .winner .name {
            font-size: 12px;
            font-weight: 700;
            color: #065f46;
            margin: 1px 0 0;
        }
        .winner .points {
            font-size: 9px;
            color: #047857;
            margin: 1px 0 0;
            font-variant-numeric: tabular-nums;
        }

        /* ───── Top 5 table ───── */
        table.rows {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 6px;
        }
        table.rows thead th {
            background-color: #1c1917;
            color: #ffffff;
            font-size: 8px;
            font-weight: 600;
            padding: 4px 6px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.rows thead th.num { text-align: right; }
        table.rows thead th.center { text-align: center; }
        table.rows tbody td {
            padding: 4px 6px;
            border-bottom: 1px solid #e7e5e4;
            font-size: 9px;
            vertical-align: middle;
        }
        table.rows tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
        table.rows tbody td.center { text-align: center; }
        table.rows tbody tr:nth-child(even) td { background-color: #fafaf9; }
        table.rows tbody tr.gold td { background-color: #fef3c7; }
        table.rows tbody tr td.rank {
            font-weight: 700;
            color: #1c1917;
            width: 28px;
            text-align: center;
        }
        table.rows tbody tr td.name { font-weight: 600; color: #1c1917; }
        table.rows tbody tr td.year { font-weight: 700; }

        /* ───── Counted nationals (per shooter) ───── */
        .counted {
            page-break-inside: avoid;
            margin: 2px 0 10px 32px;
            padding: 5px 8px;
            background: #fafaf9;
            border: 1px solid #e7e5e4;
            border-radius: 3px;
        }
        .counted .heading {
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #78716c;
            margin: 0 0 3px;
        }
        .counted .heading .who {
            color: #1c1917;
            text-transform: none;
            letter-spacing: 0;
        }
        table.counted-rows {
            width: 100%;
            border-collapse: collapse;
        }
        table.counted-rows td {
            padding: 2px 4px;
            font-size: 8px;
            color: #44403c;
        }
        table.counted-rows td.pct {
            text-align: right;
            width: 50px;
            font-variant-numeric: tabular-nums;
            color: #1c1917;
            font-weight: 600;
        }
        table.counted-rows td.tag {
            width: 44px;
            text-align: right;
        }
        .tag-chip {
            display: inline-block;
            padding: 0 4px;
            border-radius: 6px;
            font-size: 7px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .tag-chip.intl { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
        .tag-chip.home { background: #f5f5f4; color: #57534e; border: 1px solid #e7e5e4; }
        .counted .subtotal {
            font-size: 7px;
            color: #78716c;
            margin: 3px 0 0;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .counted .empty {
            font-size: 8px;
            color: #78716c;
            margin: 0;
            font-style: italic;
        }

        /* ───── Empty division ───── */
        .no-shooters {
            padding: 8px 10px;
            background: #fafaf9;
            border: 1px dashed #d6d3d1;
            font-size: 9px;
            color: #78716c;
            margin: 0 0 6px;
        }

        /* ───── Footer ───── */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7px;
            color: #a8a29e;
            padding: 6px 0;
            border-top: 1px solid #e7e5e4;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Championship Report — {{ $series }} {{ $season }}</h1>
        <p class="sub">{{ $match->name }}@if($match->match_date) · {{ $match->match_date->format('j M Y') }}@endif</p>
        <p class="meta">
            Generated {{ $generated_at->format('D j M Y, H:i') }} ·
            South African Precision Rifle Federation
        </p>
    </div>

    <p class="caption">
        Top 5 in each division by year standing, with the nationals that count on the log and today's championship contribution.
        Only shooters who shot this championship appear.
    </p>

    @if (! $has_standings)
        <div class="no-shooters">
            No year standings yet for {{ $series }} {{ $season }}. Standings must be rebuilt after the championship scores are loaded for the full report.
        </div>
    @endif

    @forelse ($divisions as $block)
        @php
            $division = $block['division'];
            $winner = $block['winner'];
            $yearMax = $block['year_max'];
            $shooters = $block['shooters_total'];
            $top5 = $block['top5'];
        @endphp
        <div class="division">
            <h2>
                {{ $division->name }}
                <span class="div-meta">
                    {{ $shooters }} {{ \Illuminate\Support\Str::plural('shooter', $shooters) }} from this division at champs
                </span>
            </h2>

            @if ($winner)
                <div class="winner">
                    <p class="label">{{ $season }} Division Winner</p>
                    <p class="name">{{ $winner['user']?->name ?? '—' }}</p>
                    <p class="points">
                        {{ number_format($winner['year_points'], 1) }}
                        @if ($yearMax) / {{ $yearMax }} @endif
                        · +{{ number_format($winner['today_points'], 1) }} from today
                    </p>
                </div>
            @endif

            @if ($top5 === [])
                <div class="no-shooters">No ranked shooters from this division shot the championship.</div>
            @else
                <table class="rows">
                    <thead>
                        <tr>
                            <th class="center" style="width: 28px;">#</th>
                            <th>Shooter</th>
                            <th class="num" style="width: 60px;">Year</th>
                            @if ($yearMax)
                                <th class="num" style="width: 38px;">Max</th>
                            @endif
                            <th class="num" style="width: 55px;">Today</th>
                            <th class="num" style="width: 50px;">Match %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($top5 as $index => $row)
                            <tr class="{{ $index === 0 ? 'gold' : '' }}">
                                <td class="rank">{{ $row['rank_label'] ?? $row['rank'] }}</td>
                                <td class="name">{{ $row['user']?->name ?? '—' }}</td>
                                <td class="num year">{{ number_format($row['year_points'], 1) }}</td>
                                @if ($yearMax)
                                    <td class="num">{{ $yearMax }}</td>
                                @endif
                                <td class="num">+{{ number_format($row['today_points'], 1) }}</td>
                                <td class="num">
                                    @if ($row['today_match_pct'] !== null)
                                        {{ number_format($row['today_match_pct'], 2) }}%
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @foreach ($top5 as $row)
                    <div class="counted">
                        <p class="heading">
                            Counted nationals · <span class="who">{{ $row['user']?->name ?? '—' }}</span>
                            ({{ count($row['counted_regulars']) }} of best {{ $row['regular_best_of'] ?: count($row['counted_regulars']) }} · shot {{ $row['nationals_shot'] }})
                        </p>
                        @if ($row['counted_regulars'] === [])
                            <p class="empty">No counting nationals — championship is their only logged match.</p>
                        @else
                            <table class="counted-rows">
                                @foreach ($row['counted_regulars'] as $reg)
                                    <tr>
                                        <td>{{ $reg['match_name'] }}</td>
                                        <td class="tag">
                                            @if ($reg['level'] === 'international')
                                                <span class="tag-chip intl">Intl</span>
                                            @elseif ($reg['home'])
                                                <span class="tag-chip home">Home</span>
                                            @endif
                                        </td>
                                        <td class="pct">{{ number_format($reg['pct'], 2) }}%</td>
                                    </tr>
                                @endforeach
                            </table>
                            <p class="subtotal">Regular subtotal {{ number_format($row['regular_total'], 2) }}</p>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>
    @empty
        <div class="no-shooters">No division scores on this championship yet.</div>
    @endforelse

    <div class="footer">SAPRF championship report · {{ $series }} {{ $season }} · {{ $match->name }} · generated {{ $generated_at->format('D j M Y, H:i') }}</div>
</body>
</html>
