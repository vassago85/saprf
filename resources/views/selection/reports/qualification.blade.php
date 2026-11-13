<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $cycle->series }} {{ $cycle->season }} selection report</title>
    <style>
        @page { margin: 28px 32px 40px 32px; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            color: #1c1917;
            font-size: 9px;
            line-height: 1.35;
            margin: 0;
        }
        .mast { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .mast td { vertical-align: middle; }
        .logo { width: 150px; }
        .logo img { height: 42px; }
        .crest {
            width: 118px;
            text-align: center;
            border: 1.5px solid #1f4d3a;
            padding: 6px 4px 5px;
        }
        .crest.iprf { border-color: #7f1d1d; }
        .crest .mark {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 1.5px;
            color: #1f4d3a;
            margin: 0;
        }
        .crest.iprf .mark { color: #7f1d1d; }
        .crest .sub {
            font-size: 6.5px;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #57534e;
            margin: 1px 0 0;
        }
        .title-cell { padding-left: 14px; }
        .kicker {
            font-size: 8px;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #1f4d3a;
            font-weight: 700;
            margin: 0;
        }
        h1 {
            font-size: 18px;
            margin: 1px 0 2px;
            color: #1c1917;
            letter-spacing: -0.2px;
        }
        .meta { font-size: 8px; color: #57534e; margin: 0; }
        .rule { border: none; border-top: 3px solid #1f4d3a; margin: 0 0 12px; }
        .summary { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 0 12px; }
        .summary td {
            width: 25%;
            background: #f5f5f4;
            border: 1px solid #e7e5e4;
            padding: 7px 8px;
        }
        .summary .label {
            font-size: 7px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: #78716c;
            margin: 0;
        }
        .summary .value { font-size: 16px; font-weight: 700; margin: 1px 0 0; color: #1c1917; }
        .summary td.good { background: #ecfdf5; border-color: #a7f3d0; }
        .summary td.good .value { color: #065f46; }
        .summary td.wait { background: #fffbeb; border-color: #fde68a; }
        .summary td.wait .value { color: #92400e; }
        .intro { font-size: 8px; color: #44403c; margin: 0 0 12px; }
        h2 {
            font-size: 13px;
            margin: 14px 0 4px;
            color: #1f4d3a;
            border-bottom: 1px solid #d6d3d1;
            padding-bottom: 2px;
        }
        table.rank { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.rank th {
            text-align: left;
            font-size: 7px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #78716c;
            border-bottom: 1px solid #a8a29e;
            padding: 4px 4px;
        }
        table.rank td {
            padding: 4px 4px;
            border-bottom: 1px solid #e7e5e4;
            vertical-align: top;
        }
        table.rank tr.alt td { background: #fafaf9; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .rank-no { font-weight: 700; color: #1f4d3a; }
        .who { font-weight: 700; }
        .subline { color: #78716c; font-size: 7.5px; }
        .pill {
            display: inline-block;
            padding: 1px 5px;
            font-size: 7px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .pill.ok { background: #d1fae5; color: #065f46; }
        .pill.wait { background: #fef3c7; color: #92400e; }
        .pill.protea { background: #fef3c7; color: #92400e; }
        .pill.fed { background: #e7e5e4; color: #44403c; }
        .evidence { page-break-before: always; }
        .card {
            border: 1px solid #e7e5e4;
            margin: 0 0 8px;
            page-break-inside: avoid;
        }
        .card-head {
            background: #1f4d3a;
            color: #ffffff;
            padding: 5px 8px;
        }
        .card-head table { width: 100%; border-collapse: collapse; }
        .card-head td { color: #ffffff; vertical-align: middle; padding: 0; }
        .card-head .nm { font-size: 11px; font-weight: 700; }
        .card-head .right { text-align: right; font-size: 8px; }
        .card-body { padding: 6px 8px 4px; }
        table.bits { width: 100%; border-collapse: collapse; }
        table.bits td { width: 50%; vertical-align: top; padding: 0 6px 0 0; }
        table.crit { width: 100%; border-collapse: collapse; }
        table.crit td { padding: 1px 3px; border-bottom: 1px solid #f5f5f4; font-size: 7.5px; }
        .met { color: #065f46; font-weight: 700; }
        .gap { color: #92400e; font-weight: 700; }
        table.matches { width: 100%; border-collapse: collapse; margin-top: 2px; }
        table.matches th {
            text-align: left;
            font-size: 7px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #78716c;
            padding: 2px 3px;
            border-bottom: 1px solid #d6d3d1;
        }
        table.matches td { padding: 2px 3px; font-size: 7.5px; border-bottom: 1px solid #f5f5f4; }
        .comp-title { font-size: 8px; font-weight: 700; margin: 4px 0 0; color: #1c1917; }
        .empty { color: #a8a29e; font-style: italic; }
        .footer {
            margin-top: 10px;
            font-size: 7px;
            color: #a8a29e;
        }
    </style>
</head>
<body>
    <table class="mast">
        <tr>
            <td class="logo">
                @if ($logo)
                    <img src="{{ $logo }}" alt="SAPRF">
                @else
                    <div class="mark">SAPRF</div>
                @endif
            </td>
            <td style="width: 8px;"></td>
            <td class="crest">
                <p class="mark">{{ $cycle->series }}</p>
                <p class="sub">{{ $cycle->series === 'PR22' ? 'Rimfire series' : 'Precision rifle' }}</p>
            </td>
            <td style="width: 8px;"></td>
            <td class="crest iprf">
                <p class="mark">IPRF</p>
                <p class="sub">World championships</p>
            </td>
            <td class="title-cell">
                <p class="kicker">South African Precision Rifle Federation</p>
                <h1>{{ $cycle->series }} {{ $cycle->season }} selection report</h1>
                <p class="meta">
                    {{ $cycle->championship_name }}
                    · Qualifying {{ $cycle->qualifying_period_start?->format('j M Y') }} – {{ $cycle->qualifying_period_end?->format('j M Y') }}
                    · Declaration deadline {{ $cycle->declaration_deadline?->format('j M Y') }}
                    · Prepared {{ $generatedAt->timezone(config('app.timezone'))->format('j M Y H:i') }}
                </p>
            </td>
        </tr>
    </table>
    <hr class="rule">

    <table class="summary">
        <tr>
            <td class="good">
                <p class="label">Criteria complete</p>
                <p class="value">{{ $report['summary']['qualified'] }}</p>
            </td>
            <td class="wait">
                <p class="label">Declaration still due</p>
                <p class="value">{{ $report['summary']['declaration_outstanding'] }}</p>
            </td>
            <td>
                <p class="label">Divisions</p>
                <p class="value">{{ $report['summary']['divisions'] }}</p>
            </td>
            <td>
                <p class="label">Protea line</p>
                <p class="value">{{ rtrim(rtrim(number_format($report['threshold'], 1), '0'), '.') }}%</p>
            </td>
        </tr>
    </table>

    <p class="intro">
        Listed shooters have met every eligibility and participation criterion, or have met every criterion except the Eligibility to Compete declaration.
        Rank is within the claimed division, on the policy weighted score: top three provincial 1-day results at 10% each, top two national or international 2-day results at 20% each, and the SA Championship at 30%.
        Division % is that score scaled so the division leader is 100. {{ rtrim(rtrim(number_format($report['threshold'], 1), '0'), '.') }}% and above is the Protea line used in the policy; below it is Federation colours.
        This paper is the evidence for selectors. It is not the announced team.
    </p>

    @forelse ($report['divisions'] as $division)
        <h2>{{ $division['name'] }}</h2>
        <table class="rank">
            <thead>
                <tr>
                    <th style="width: 28px;">Rank</th>
                    <th>Shooter</th>
                    <th>Club</th>
                    <th>Standing</th>
                    <th class="num">Criteria</th>
                    <th class="num">Weighted</th>
                    <th class="num">Division %</th>
                    <th>Colours line</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($division['shooters'] as $index => $shooter)
                    <tr class="{{ $index % 2 === 1 ? 'alt' : '' }}">
                        <td class="rank-no">{{ $shooter['rank'] }}</td>
                        <td>
                            <div class="who">{{ $shooter['name'] }}</div>
                            <div class="subline">
                                {{ $shooter['province'] ?: '—' }}
                                @if ($shooter['saprf_number']) · {{ $shooter['saprf_number'] }} @endif
                            </div>
                        </td>
                        <td>{{ $shooter['club'] ?: '—' }}</td>
                        <td>
                            @if ($shooter['status'] === 'qualified')
                                <span class="pill ok">Qualified</span>
                            @else
                                <span class="pill wait">Declaration due</span>
                            @endif
                        </td>
                        <td class="num">{{ $shooter['criteria_met'] }}/{{ $shooter['criteria_total'] }}</td>
                        <td class="num">{{ number_format($shooter['raw_weighted_pct'], 2) }}</td>
                        <td class="num">{{ number_format($shooter['division_pct'], 2) }}</td>
                        <td>
                            @if ($shooter['colour'] === 'protea')
                                <span class="pill protea">Protea line</span>
                            @else
                                <span class="pill fed">Federation</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p class="empty">No shooter has met every criterion yet, and none are waiting only on the declaration.</p>
    @endforelse

    @if ($report['divisions'] !== [])
        <div class="evidence">
            <p class="kicker">Supporting evidence</p>
            <h1 style="font-size: 16px;">Counted matches and criteria</h1>
            <p class="intro">Each block shows the criteria result and the matches that were actually counted toward the weighted score. Where fewer than the required matches were available, only the matches on file are counted.</p>

            @foreach ($report['divisions'] as $division)
                @foreach ($division['shooters'] as $shooter)
                    <div class="card">
                        <div class="card-head">
                            <table>
                                <tr>
                                    <td>
                                        <div class="nm">{{ $shooter['rank'] }}. {{ $shooter['name'] }}</div>
                                        <div style="font-size: 8px;">{{ $division['name'] }} · {{ $shooter['club'] ?: 'No club' }} · {{ $shooter['email'] }}</div>
                                    </td>
                                    <td class="right">
                                        Weighted {{ number_format($shooter['raw_weighted_pct'], 2) }}
                                        · Division {{ number_format($shooter['division_pct'], 2) }}%<br>
                                        {{ $shooter['status'] === 'qualified' ? 'All criteria met' : 'Declaration still outstanding' }}
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="card-body">
                            <table class="bits">
                                <tr>
                                    <td>
                                        <table class="crit">
                                            @foreach ($shooter['criteria'] as $rule)
                                                <tr>
                                                    <td style="width: 42px;">{{ $rule['code'] }}</td>
                                                    <td class="{{ $rule['status'] === 'met' ? 'met' : 'gap' }}">{{ $rule['status'] === 'met' ? 'Met' : 'Open' }}</td>
                                                    <td>{{ $rule['note'] }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                    <td>
                                        @foreach ($shooter['components'] as $component)
                                            <p class="comp-title">{{ $component['label'] }} · {{ number_format($component['contribution'], 2) }} pts ({{ $component['counted'] }}/{{ $component['take'] }})</p>
                                            @if ($component['matches'] === [])
                                                <p class="empty">No counted match.</p>
                                            @else
                                                <table class="matches">
                                                    <thead>
                                                        <tr>
                                                            <th>Match</th>
                                                            <th>Date</th>
                                                            <th class="num">Match %</th>
                                                            <th class="num">Weight</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($component['matches'] as $match)
                                                            <tr>
                                                                <td>{{ $match['name'] }}</td>
                                                                <td>{{ $match['date'] }}</td>
                                                                <td class="num">{{ number_format($match['normalized'], 2) }}</td>
                                                                <td class="num">{{ number_format($match['contribution'], 2) }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            @endif
                                        @endforeach
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    @endif

    <p class="footer">
        SAPRF selector working paper · {{ $cycle->series }} {{ $cycle->season }} · Policy v{{ $cycle->activePolicy?->version ?? '—' }}
        · Weighted score follows the published selection formula. Colours remain subject to the selection panel and to SASCOC for Protea.
    </p>
</body>
</html>
