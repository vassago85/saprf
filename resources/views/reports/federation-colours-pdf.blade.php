<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SAPRF Federation Colours — {{ $seriesLabel }} {{ $season }}</title>
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
        .header .org {
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

        /* ───── Summary strip ───── */
        .summary {
            width: 100%;
            margin: 0 0 14px;
            border-collapse: separate;
            border-spacing: 4px 0;
        }
        .summary td {
            width: 16.66%;
            background: #f5f5f4;
            border: 1px solid #e7e5e4;
            border-radius: 4px;
            padding: 6px 8px;
            vertical-align: top;
        }
        .summary td.gold { background: #fef3c7; border-color: #fde68a; }
        .summary td.silver { background: #e7e5e4; border-color: #d6d3d1; }
        .summary td.bronze { background: #ffedd5; border-color: #fed7aa; }
        .summary .label {
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #78716c;
            margin: 0;
        }
        .summary td.gold .label { color: #92400e; }
        .summary td.silver .label { color: #57534e; }
        .summary td.bronze .label { color: #9a3412; }
        .summary .value {
            font-size: 16px;
            font-weight: 700;
            color: #1c1917;
            margin: 2px 0 0;
        }
        .summary td.gold .value { color: #92400e; }
        .summary td.silver .value { color: #44403c; }
        .summary td.bronze .value { color: #9a3412; }

        /* ───── Thresholds caption ───── */
        .caption {
            font-size: 9px;
            color: #57534e;
            margin: 0 0 14px;
            padding: 6px 10px;
            background: #fafaf9;
            border-left: 3px solid #047857;
        }

        /* ───── Division + province headings ───── */
        .division {
            page-break-inside: auto;
            margin-bottom: 10px;
        }
        .division h2 {
            font-size: 13px;
            font-weight: 700;
            margin: 14px 0 6px;
            padding: 5px 10px;
            background: #047857;
            color: #ffffff;
            border-radius: 3px;
        }
        .province-block {
            page-break-inside: avoid;
            margin-bottom: 10px;
        }
        .province-head {
            display: block;
            padding: 4px 8px;
            background: #ecfdf5;
            border-left: 3px solid #047857;
            margin-bottom: 0;
        }
        .province-head .pname {
            font-size: 11px;
            font-weight: 700;
            color: #065f46;
            margin: 0;
        }
        .province-head .leader {
            font-size: 8px;
            color: #047857;
            margin: 1px 0 0;
        }
        .shown-of {
            float: right;
            font-size: 7px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #047857;
            font-weight: 600;
            margin-top: 2px;
        }

        /* ───── Table ───── */
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
        table.rows tbody td.mono { font-family: 'Courier New', Courier, monospace; font-size: 8px; color: #57534e; }
        table.rows tbody td.center { text-align: center; }
        table.rows tbody tr:nth-child(even) td { background-color: #fafaf9; }
        table.rows tbody tr.gold td { background-color: #fef3c7; }
        table.rows tbody tr.silver td { background-color: #e7e5e4; }
        table.rows tbody tr.bronze td { background-color: #ffedd5; }
        table.rows tbody tr td.rank {
            font-weight: 700;
            color: #1c1917;
            width: 28px;
            text-align: center;
        }
        table.rows tbody tr td.name { font-weight: 600; color: #1c1917; }
        table.rows tbody tr td.pct { font-weight: 700; }

        /* ───── Grade pills ───── */
        .pill {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 7px;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .pill.g { background: #fde68a; color: #92400e; border: 1px solid #fcd34d; }
        .pill.s { background: #d6d3d1; color: #44403c; border: 1px solid #a8a29e; }
        .pill.b { background: #fed7aa; color: #9a3412; border: 1px solid #fdba74; }

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
    @php
        $summary = $report['summary'] ?? ['total_rows' => 0, 'gold' => 0, 'silver' => 0, 'bronze' => 0, 'divisions' => 0, 'provinces' => 0];
        $topN = $filters['top_n'] ?? null;
        $showAwards = $showAwards ?? (($filters['awards'] ?? '1') === '1');
        $reportTitle = $showAwards ? 'Federation Colours' : 'Provincial Standings';
    @endphp

    <div class="header">
        <h1>{{ $reportTitle }} — {{ $seriesLabel }} {{ $season }}</h1>
        <p class="org">South African Precision Rifle Federation</p>
        <p class="meta">
            Generated {{ $generatedAt }}.
            @if ($topN)
                Showing top {{ $topN }} shooters per province.
            @else
                Showing all qualifying shooters.
            @endif
            @if ($showAwards && $filters['require_champs'] === '1')
                Grades require a valid SA Championships score in that division (or a recorded override).
            @elseif (! $showAwards)
                Ranking sheet only. Grades are not printed on this copy.
            @endif
        </p>
    </div>

    @if ($summary['total_rows'] > 0)
        <table class="summary">
            <tr>
                <td>
                    <p class="label">Shooters</p>
                    <p class="value">{{ $summary['total_rows'] }}</p>
                </td>
                <td>
                    <p class="label">Divisions</p>
                    <p class="value">{{ $summary['divisions'] }}</p>
                </td>
                <td>
                    <p class="label">Province rows</p>
                    <p class="value">{{ $summary['provinces'] }}</p>
                </td>
                @if ($showAwards)
                <td class="gold">
                    <p class="label">Gold</p>
                    <p class="value">{{ $summary['gold'] }}</p>
                </td>
                <td class="silver">
                    <p class="label">Silver</p>
                    <p class="value">{{ $summary['silver'] }}</p>
                </td>
                <td class="bronze">
                    <p class="label">Bronze</p>
                    <p class="value">{{ $summary['bronze'] }}</p>
                </td>
                @endif
            </tr>
        </table>
    @endif

    <p class="caption">
        Percentage is of the highest provincial points in that division and province.
        @if ($showAwards)
            Gold {{ number_format($filters['gold'], 2) }}%+ ·
            Silver {{ number_format($filters['silver'], 2) }}%+ ·
            Bronze {{ number_format($filters['bronze'], 2) }}%+ ·
        @endif
        Open and Factory are listed separately.
    </p>

    @forelse ($report['divisions'] as $division)
        <div class="division">
            <h2>{{ $division['name'] }}</h2>
            @foreach ($division['provinces'] as $province)
                <div class="province-block">
                    <div class="province-head">
                        @if (isset($province['total_shooters']) && $province['total_shooters'] > count($province['rows']))
                            <span class="shown-of">Top {{ count($province['rows']) }} of {{ $province['total_shooters'] }}</span>
                        @endif
                        <p class="pname">{{ $province['name'] }}</p>
                        <p class="leader">
                            Highest: {{ $province['leader_name'] }}
                            ({{ number_format($province['leader_points'], 2) }} pts = 100%)
                        </p>
                    </div>
                    <table class="rows">
                        <thead>
                            <tr>
                                <th class="center" style="width: 28px;">#</th>
                                <th>Shooter</th>
                                <th style="width: 70px;">Member #</th>
                                <th class="num" style="width: 55px;">Points</th>
                                <th class="num" style="width: 40px;">Matches</th>
                                <th class="num" style="width: 45px;">%</th>
                                @if ($showAwards)
                                    <th class="center" style="width: 50px;">SA Champs</th>
                                    @if ($filters['require_champs'] === '1')
                                        <th style="width: 110px;">Override reason</th>
                                    @endif
                                    <th class="center" style="width: 48px;">Grade</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($province['rows'] as $row)
                                @php
                                    $rowClass = $showAwards ? match ($row['grade'] ?? null) {
                                        'Gold' => 'gold',
                                        'Silver' => 'silver',
                                        'Bronze' => 'bronze',
                                        default => '',
                                    } : '';
                                @endphp
                                <tr class="{{ $rowClass }}">
                                    <td class="rank">{{ $row['rank'] }}</td>
                                    <td class="name">{{ $row['name'] }}</td>
                                    <td class="mono">{{ $row['saprf_number'] ?? '—' }}</td>
                                    <td class="num">{{ number_format($row['points'], 2) }}</td>
                                    <td class="num">{{ $row['matches_counted'] ?? '—' }}</td>
                                    <td class="num pct">{{ $row['percentage'] !== null ? number_format($row['percentage'], 2) : '—' }}</td>
                                    @if ($showAwards)
                                    <td class="center">{{ $row['shot_champs'] ? 'Yes' : 'No' }}</td>
                                    @if ($filters['require_champs'] === '1')
                                        <td>{{ $row['override_reason'] ?? '—' }}</td>
                                    @endif
                                    <td class="center">
                                        @if ($row['grade'] === 'Gold')
                                            <span class="pill g">Gold</span>
                                        @elseif ($row['grade'] === 'Silver')
                                            <span class="pill s">Silver</span>
                                        @elseif ($row['grade'] === 'Bronze')
                                            <span class="pill b">Bronze</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    @empty
        <p>No provincial division standings for {{ $seriesLabel }} {{ $season }}.</p>
    @endforelse

    <div class="footer">SAPRF {{ $showAwards ? 'federation colours' : 'provincial standings' }} · {{ $series }} {{ $season }} · generated {{ $generatedAt }}</div>
</body>
</html>
