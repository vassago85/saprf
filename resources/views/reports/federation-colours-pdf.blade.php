<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SAPRF Federation Colours — {{ $seriesLabel }} {{ $season }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1c1917;
            margin: 18px;
            line-height: 1.35;
        }
        h1 {
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 2px;
            color: #047857;
        }
        h2 {
            font-size: 13px;
            margin: 16px 0 4px;
            color: #1c1917;
        }
        h3 {
            font-size: 11px;
            margin: 10px 0 2px;
            color: #44403c;
        }
        .subtitle { font-size: 11px; color: #57534e; margin: 0 0 2px; }
        .meta { font-size: 9px; color: #78716c; margin: 0 0 8px; }
        .leader { font-size: 9px; color: #57534e; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        thead th {
            background-color: #047857;
            color: #ffffff;
            font-size: 8px;
            font-weight: 600;
            padding: 4px 5px;
            text-align: left;
        }
        thead th.num, tbody td.num { text-align: right; }
        tbody tr:nth-child(even) { background-color: #f5f5f4; }
        tbody td {
            padding: 3px 5px;
            border-bottom: 1px solid #e7e5e4;
            font-size: 9px;
        }
        .division { page-break-inside: avoid; }
        .division + .division { page-break-before: always; }
        .footer {
            margin-top: 16px;
            text-align: center;
            font-size: 8px;
            color: #a8a29e;
            border-top: 1px solid #e7e5e4;
            padding-top: 8px;
        }
    </style>
</head>
<body>
    <h1>Federation Colours — {{ $seriesLabel }} {{ $season }}</h1>
    <p class="subtitle">South African Precision Rifle Federation</p>
    <p class="meta">
        Generated {{ $generatedAt }}.
        Percentage is of the highest provincial points in that division and province.
        Gold 95% and above, Silver 85% up to 95%, Bronze 80% up to 85%.
        Open and Factory are listed separately.
    </p>

    @forelse ($report['divisions'] as $division)
        <div class="division">
            <h2>{{ $division['name'] }}</h2>
            @foreach ($division['provinces'] as $province)
                <h3>{{ $province['name'] }}</h3>
                <p class="leader">
                    Highest: {{ $province['leader_name'] }}
                    ({{ number_format($province['leader_points'], 2) }} pts = 100%)
                </p>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Shooter</th>
                            <th>Member #</th>
                            <th class="num">Points</th>
                            <th class="num">Matches</th>
                            <th class="num">%</th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($province['rows'] as $row)
                            <tr>
                                <td>{{ $row['rank'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['saprf_number'] ?? '—' }}</td>
                                <td class="num">{{ number_format($row['points'], 2) }}</td>
                                <td class="num">{{ $row['matches_counted'] ?? '—' }}</td>
                                <td class="num">{{ $row['percentage'] !== null ? number_format($row['percentage'], 2) : '—' }}</td>
                                <td>{{ $row['grade'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
        </div>
    @empty
        <p>No provincial division standings for {{ $seriesLabel }} {{ $season }}.</p>
    @endforelse

    <div class="footer">SAPRF federation colours report · {{ $series }} {{ $season }}</div>
</body>
</html>
