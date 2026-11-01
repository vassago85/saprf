<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $match->name }} — Results</title>
    <style>
        /* Phase 4.9: minimal, printer-friendly styling. DomPDF is strict so
           we stick to standard CSS the engine already supports. */
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1c1917; font-size: 10pt; }
        h1 { font-size: 16pt; margin: 0 0 4px; }
        .meta { color: #78716c; font-size: 9pt; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.08em; color: #78716c; border-bottom: 1px solid #d6d3d1; padding: 6px 4px; }
        td { padding: 6px 4px; border-bottom: 1px solid #f5f5f4; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .footer { margin-top: 18px; font-size: 8pt; color: #a8a29e; }
    </style>
</head>
<body>
    <h1>{{ $match->name }}@if($divisionName) — {{ $divisionName }}@endif</h1>
    <p class="meta">
        {{ $match->match_date->format('j F Y') }}@if($match->isMultiDay()) – {{ $match->match_end_date->format('j F Y') }}@endif
        @if($match->venue_name) · {{ $match->venue_name }}@endif
        @if($match->location_display) · {{ $match->location_display }}@endif
        · {{ $scores->count() }} {{ \Illuminate\Support\Str::plural('shooter', $scores->count()) }}
    </p>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Shooter</th>
                <th>Division</th>
                <th class="num">Score</th>
                <th class="num">Match %</th>
                <th class="num">Div %</th>
            </tr>
        </thead>
        <tbody>
            @foreach($scores as $score)
                @php
                    $divLeader = $score->division_id ? ($divisionLeaderRaw[$score->division_id] ?? 0.0) : 0.0;
                    $matchPct = $overallLeaderRaw > 0 && $score->raw_score !== null
                        ? round(((float) $score->raw_score) / $overallLeaderRaw * 100, 2)
                        : null;
                    $divPct = $divLeader > 0 && $score->raw_score !== null
                        ? round(((float) $score->raw_score) / $divLeader * 100, 2)
                        : null;
                    $rankLabel = $divisionName
                        ? ($score->division_rank_label ?? '—')
                        : ($score->overall_rank_label ?? '—');
                @endphp
                <tr>
                    <td>{{ $rankLabel }}</td>
                    <td>{{ $score->shooter_name }}</td>
                    <td>{{ $score->division?->name ?? '—' }}</td>
                    <td class="num">{{ \App\Support\ScoreFormat::format($score->raw_score, $match->match_type, $match->series) }}</td>
                    <td class="num">{{ $matchPct !== null ? number_format($matchPct, 2).'%' : '—' }}</td>
                    <td class="num">{{ $divPct !== null ? number_format($divPct, 2).'%' : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="footer">Generated {{ now()->format('j M Y, H:i') }} · SAPRF match results · pretoriaprc.co.za/events/{{ $match->id }}</p>
</body>
</html>
