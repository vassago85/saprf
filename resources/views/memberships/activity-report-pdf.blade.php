<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAPRF Activity Report — {{ $membership->saprf_number }} — {{ $season }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=Saira+Condensed:wght@600;700&family=Saira:wght@600&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #006838;
            --gold: #C9971C;
            --gold-deep: #A57B12;
            --ink: #171B17;
            --slate: #6C756E;
            --line: #E4E2DC;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        @page {
            size: A4;
            margin: 12mm 14mm 14mm;
        }

        html, body {
            background: #FFFFFF;
            color: var(--ink);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        body {
            font-family: 'IBM Plex Mono', monospace;
        }

        .sheet {
            position: relative;
            width: 100%;
            min-height: 273mm;
            padding: 8mm 6mm 6mm;
            border: 0.55mm solid var(--gold);
            outline: 0.35mm solid color-mix(in srgb, var(--green) 50%, transparent);
            outline-offset: -2.2mm;
            background: #FFFFFF;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Fallback outline colour for engines without color-mix */
        @supports not (outline-color: color-mix(in srgb, red 50%, transparent)) {
            .sheet {
                box-shadow: inset 0 0 0 2.2mm #FFFFFF, inset 0 0 0 2.55mm rgba(0, 104, 56, 0.45);
            }
        }

        .corner {
            position: absolute;
            width: 7mm;
            height: 7mm;
            border-color: var(--gold);
            border-style: solid;
            border-width: 0;
            z-index: 2;
            pointer-events: none;
        }
        .corner--tl { top: -0.2mm; left: -0.2mm; border-top-width: 0.9mm; border-left-width: 0.9mm; }
        .corner--tr { top: -0.2mm; right: -0.2mm; border-top-width: 0.9mm; border-right-width: 0.9mm; }
        .corner--bl { bottom: -0.2mm; left: -0.2mm; border-bottom-width: 0.9mm; border-left-width: 0.9mm; }
        .corner--br { bottom: -0.2mm; right: -0.2mm; border-bottom-width: 0.9mm; border-right-width: 0.9mm; }

        .content {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5mm;
        }

        .logo {
            width: 78mm;
            height: auto;
            display: block;
        }

        .ornament {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3mm;
        }

        .ornament-rule {
            width: 22mm;
            height: 0.35mm;
            background: var(--gold);
        }

        .ornament-diamond {
            width: 2mm;
            height: 2mm;
            background: var(--gold);
            transform: rotate(45deg);
            flex-shrink: 0;
        }

        .title {
            font-family: 'Saira Condensed', sans-serif;
            font-weight: 700;
            font-size: 26pt;
            line-height: 1;
            color: var(--green);
            letter-spacing: .06em;
            text-align: center;
            text-transform: uppercase;
        }

        .subtitle {
            margin-top: -2mm;
            font-size: 6.4pt;
            color: var(--gold-deep);
            letter-spacing: .32em;
            text-align: center;
            text-transform: uppercase;
        }

        .card {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 2mm;
            background: #FFFFFF;
            overflow: hidden;
            page-break-inside: avoid;
        }

        .card-pad {
            padding: 4.5mm 5mm;
        }

        .card-header {
            height: 4.2mm;
            background: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-header span,
        .card-header-gold span {
            font-size: 6.2pt;
            color: #FFFFFF;
            letter-spacing: .32em;
            text-transform: uppercase;
        }

        .card-header-gold {
            height: 4.2mm;
            background: var(--gold);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .identity {
            text-align: center;
            padding: 5mm 5mm 4mm;
        }

        .eyebrow {
            font-size: 6.8pt;
            color: var(--slate);
            letter-spacing: .3em;
            text-transform: uppercase;
        }

        .member-name {
            margin-top: 2.5mm;
            font-family: 'Saira', sans-serif;
            font-weight: 600;
            font-size: {{ $memberNameSize }};
            line-height: 1.1;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
        }

        .status-chip {
            display: inline-block;
            margin-top: 3mm;
            padding: 1.4mm 4mm;
            border-radius: 999px;
            border: 0.45mm solid var(--gold);
            background: rgba(201, 151, 28, .06);
            font-size: 6.8pt;
            color: var(--green);
            letter-spacing: .28em;
            text-transform: uppercase;
        }

        .status-chip.is-muted {
            border-color: var(--slate);
            color: var(--slate);
            background: transparent;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2.5mm 6mm;
            padding: 4mm 5mm 4.5mm;
            border-top: 1px solid var(--line);
        }

        .meta-item .label {
            font-size: 6pt;
            color: var(--slate);
            letter-spacing: .28em;
            text-transform: uppercase;
        }

        .meta-item .value {
            margin-top: 1mm;
            font-size: 8.5pt;
            font-weight: 600;
            color: var(--ink);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 3mm;
            width: 100%;
        }

        .stat {
            border: 1px solid var(--line);
            border-radius: 2mm;
            padding: 3.5mm 3mm;
            text-align: center;
            background: #FFFFFF;
            page-break-inside: avoid;
        }

        .stat-value {
            font-family: 'Saira Condensed', sans-serif;
            font-weight: 700;
            font-size: 20pt;
            line-height: 1;
            color: var(--green);
            letter-spacing: .02em;
        }

        .stat-label {
            margin-top: 1.8mm;
            font-size: 6pt;
            color: var(--slate);
            letter-spacing: .28em;
            text-transform: uppercase;
        }

        .match-table {
            width: 100%;
            border-collapse: collapse;
        }

        .match-table th {
            font-size: 6pt;
            font-weight: 400;
            color: var(--slate);
            letter-spacing: .22em;
            text-transform: uppercase;
            text-align: left;
            padding: 2.8mm 2.2mm;
            border-bottom: 1px solid var(--line);
            background: #FAFAF8;
        }

        .match-table th.num,
        .match-table td.num {
            text-align: right;
        }

        .match-table td {
            font-size: 7.4pt;
            font-weight: 400;
            color: var(--ink);
            padding: 2.4mm 2.2mm;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        .match-table tr:last-child td {
            border-bottom: none;
        }

        .match-table .name {
            font-weight: 600;
            max-width: 42mm;
        }

        .match-table .mono {
            font-weight: 600;
        }

        .empty {
            padding: 8mm 5mm;
            text-align: center;
            font-size: 7.5pt;
            color: var(--slate);
            letter-spacing: .04em;
        }

        .standings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3mm;
            padding: 4mm 4mm 4.5mm;
        }

        .standing-block {
            border: 1px solid var(--line);
            border-radius: 1.5mm;
            padding: 3.5mm;
        }

        .standing-series {
            font-family: 'Saira Condensed', sans-serif;
            font-weight: 700;
            font-size: 12pt;
            color: var(--green);
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .standing-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-top: 2.2mm;
            font-size: 7pt;
        }

        .standing-row .label {
            color: var(--slate);
            letter-spacing: .18em;
            text-transform: uppercase;
        }

        .standing-row .value {
            font-weight: 600;
            color: var(--ink);
        }

        .footer {
            width: 100%;
            margin-top: 2mm;
            padding-top: 3.5mm;
            border-top: 0.25mm solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4mm;
            page-break-inside: avoid;
        }

        .footer-copy {
            flex: 1 1 auto;
            min-width: 0;
        }

        .footer-line {
            font-size: 5.6pt;
            color: var(--slate);
            letter-spacing: .03em;
            line-height: 1.55;
        }

        .footer-line + .footer-line {
            margin-top: 1mm;
        }

        .qr-chip {
            position: relative;
            width: 26mm;
            height: 26mm;
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #FFFFFF;
        }

        .qr-chip img {
            width: 20mm;
            height: 20mm;
            display: block;
        }

        .qr-corner {
            position: absolute;
            width: 3.8mm;
            height: 3.8mm;
            border-color: var(--gold);
            border-style: solid;
            border-width: 0;
        }

        .qr-corner--tl { top: 0; left: 0; border-top-width: 0.45mm; border-left-width: 0.45mm; }
        .qr-corner--tr { top: 0; right: 0; border-top-width: 0.45mm; border-right-width: 0.45mm; }
        .qr-corner--bl { bottom: 0; left: 0; border-bottom-width: 0.45mm; border-left-width: 0.45mm; }
        .qr-corner--br { bottom: 0; right: 0; border-bottom-width: 0.45mm; border-right-width: 0.45mm; }
    </style>
</head>
<body>
    <div class="sheet">
        <span class="corner corner--tl"></span>
        <span class="corner corner--tr"></span>
        <span class="corner corner--bl"></span>
        <span class="corner corner--br"></span>

        <div class="content">
            <img class="logo" src="{{ $logoBase64 }}" alt="South African Precision Rifle Federation">

            <div class="ornament" aria-hidden="true">
                <span class="ornament-rule"></span>
                <span class="ornament-diamond"></span>
                <span class="ornament-rule"></span>
            </div>

            <h1 class="title">Activity Report</h1>
            <p class="subtitle">{{ $season }} Season · Official Activity Record</p>

            <div class="card">
                <div class="identity">
                    <p class="eyebrow">Prepared for</p>
                    <p class="member-name">{{ $user->name }}</p>
                    <span class="status-chip {{ $chipMuted ? 'is-muted' : '' }}">{{ $statusLabel }}</span>
                </div>
                <div class="meta-grid">
                    <div class="meta-item">
                        <div class="label">SAPRF No</div>
                        <div class="value">{{ $membership->saprf_number ?: '—' }}</div>
                    </div>
                    <div class="meta-item">
                        <div class="label">Province</div>
                        <div class="value">{{ $user->province?->name ?? '—' }}</div>
                    </div>
                    <div class="meta-item">
                        <div class="label">Valid Until</div>
                        <div class="value">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</div>
                    </div>
                    <div class="meta-item">
                        <div class="label">Season</div>
                        <div class="value">{{ $season }}</div>
                    </div>
                </div>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-value">{{ $stats['total'] }}</div>
                    <div class="stat-label">Matches</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{{ $stats['prs'] }}</div>
                    <div class="stat-label">PRS</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{{ $stats['pr22'] }}</div>
                    <div class="stat-label">PR22</div>
                </div>
            </div>

            <div class="card" style="page-break-inside: auto;">
                <div class="card-header"><span>Match History</span></div>
                @if($scores->isEmpty())
                    <div class="empty">No completed matches found for the {{ $season }} season.</div>
                @else
                    <table class="match-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Match</th>
                                <th>Series</th>
                                <th>Level</th>
                                <th>Division</th>
                                <th class="num">Impacts</th>
                                <th class="num">%</th>
                                <th class="num">Rank</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($scores as $score)
                                <tr>
                                    <td>{{ $score->match_date?->format('d M Y') ?? '—' }}</td>
                                    <td class="name">{{ \Illuminate\Support\Str::limit($score->match->name ?? '—', 28) }}</td>
                                    <td>{{ $score->match->match_type ?? $score->match->series ?? '—' }}</td>
                                    <td>{{ ucfirst($score->match->series_level ?? '—') }}</td>
                                    <td>{{ $score->division->name ?? 'Open' }}</td>
                                    <td class="num mono">{{ number_format((float) $score->raw_score, 0) }}</td>
                                    <td class="num mono">{{ number_format((float) $score->normalized_score, 2) }}</td>
                                    <td class="num mono">{{ $score->overall_rank ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if($includeStandings && count($standingsSummary) > 0)
                <div class="card">
                    <div class="card-header-gold"><span>Season Standings</span></div>
                    <div class="standings-grid">
                        @foreach($standingsSummary as $standing)
                            <div class="standing-block">
                                <div class="standing-series">{{ $standing['series'] }}</div>
                                <div class="standing-row">
                                    <span class="label">Overall rank</span>
                                    <span class="value">#{{ $standing['overall_rank'] }}</span>
                                </div>
                                <div class="standing-row">
                                    <span class="label">Overall points</span>
                                    <span class="value">{{ number_format((float) $standing['overall_points'], 2) }}</span>
                                </div>
                                @if($standing['division_name'])
                                    <div class="standing-row">
                                        <span class="label">{{ $standing['division_name'] }}</span>
                                        <span class="value">#{{ $standing['division_rank'] }} · {{ number_format((float) $standing['division_points'], 2) }}</span>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="footer">
                <div class="footer-copy">
                    <p class="footer-line">This activity report is electronically generated and verifiable via the QR code. It may be submitted in support of dedicated status or firearm licence applications.</p>
                    <p class="footer-line">{{ $verifyUrl }}</p>
                    <p class="footer-line">Generated {{ $generatedDate }} · {{ $generatedTime }} SAST</p>
                </div>
                <div class="qr-chip">
                    <span class="qr-corner qr-corner--tl"></span>
                    <span class="qr-corner qr-corner--tr"></span>
                    <span class="qr-corner qr-corner--bl"></span>
                    <span class="qr-corner qr-corner--br"></span>
                    <img src="{{ $qrBase64 }}" alt="Membership verification QR code">
                </div>
            </div>
        </div>
    </div>
</body>
</html>
