<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SAPRF Activity Report — {{ $membership->saprf_number }} — {{ $season }}</title>
    @include('memberships.partials.certificate-fonts')
    <style>
        @page { margin: 0; size: A4 portrait; }
        * { margin: 0; padding: 0; }
        body {
            margin: 0;
            padding: 0;
            background: #FFFFFF;
            color: #171B17;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
        }
        .page {
            position: relative;
            width: 210mm;
            height: 297mm;
            overflow: hidden;
            background: #FFFFFF;
        }
        .frame {
            position: fixed;
            top: 0;
            left: 0;
            width: 210mm;
            height: 297mm;
            z-index: 0;
        }
        .content {
            position: relative;
            z-index: 1;
            padding: 16mm 16mm 22mm;
        }
        .logo {
            display: block;
            width: 70mm;
        }
        .title {
            font-family: 'Saira Condensed', DejaVu Sans, sans-serif;
            font-weight: 700;
            font-size: 24pt;
            color: #006838;
            letter-spacing: 0.06em;
            text-align: center;
            text-transform: uppercase;
            margin-top: 3.5mm;
        }
        .subtitle {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400;
            font-size: 6.2pt;
            color: #A57B12;
            letter-spacing: 0.2em;
            text-align: center;
            text-transform: uppercase;
            margin-top: 1.8mm;
        }
        .card {
            border: 1px solid #E4E2DC;
            border-radius: 2mm;
            background: #FFFFFF;
        }
        .card-prepared {
            width: 170mm;
            margin: 4.5mm auto 0;
            padding: 5mm 6mm;
            text-align: center;
        }
        .eyebrow {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400;
            font-size: 6.6pt;
            color: #6C756E;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }
        .member-name {
            font-family: 'Saira', DejaVu Sans, sans-serif;
            font-weight: 600;
            font-size: {{ $memberNameSize }};
            color: #171B17;
            margin-top: 2mm;
            white-space: nowrap;
        }
        .status-chip {
            display: inline-block;
            margin-top: 2.5mm;
            padding: 1.2mm 3.8mm;
            border: 0.45mm solid #C9971C;
            border-radius: 10mm;
            background: #FBF6EA;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.6pt;
            color: #006838;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .status-chip.is-muted {
            border-color: #6C756E;
            color: #6C756E;
            background: #FFFFFF;
        }
        .summary-table {
            width: 170mm;
            margin: 4mm auto 0;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .summary-table td {
            width: 25%;
            border: 1px solid #E4E2DC;
            padding: 3mm 2mm;
            text-align: center;
            vertical-align: top;
        }
        .summary-label {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 5.8pt;
            color: #6C756E;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .summary-value {
            margin-top: 1.2mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 600;
            font-size: 8pt;
            color: #171B17;
        }
        .stats-table {
            width: 170mm;
            margin: 4mm auto 0;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .stats-table td {
            width: 33.33%;
            border: 1px solid #E4E2DC;
            padding: 4mm 2mm;
            text-align: center;
            vertical-align: middle;
        }
        .stat-value {
            font-family: 'Saira Condensed', DejaVu Sans, sans-serif;
            font-weight: 700;
            font-size: 22pt;
            color: #006838;
            line-height: 1;
        }
        .stat-label {
            margin-top: 1.5mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6pt;
            color: #6C756E;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .card-history {
            width: 170mm;
            margin: 4mm auto 0;
            overflow: hidden;
        }
        .section-header {
            background: #006838;
            padding: 1.6mm 4mm;
            text-align: center;
        }
        .section-header span {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.2pt;
            color: #FFFFFF;
            letter-spacing: 0.2em;
            text-transform: uppercase;
        }
        .section-header-gold {
            background: #C9971C;
            padding: 1.6mm 4mm;
            text-align: center;
        }
        .section-header-gold span {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.2pt;
            color: #FFFFFF;
            letter-spacing: 0.2em;
            text-transform: uppercase;
        }
        .empty {
            padding: 6mm 4mm;
            text-align: center;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 7pt;
            color: #6C756E;
        }
        .match-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .match-table th {
            background: #F4F3EF;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400;
            font-size: 6pt;
            color: #6C756E;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            text-align: left;
            padding: 2mm 1.8mm;
            border-bottom: 0.2mm solid #E4E2DC;
        }
        .match-table th.num,
        .match-table td.num {
            text-align: right;
        }
        .match-table td {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 7pt;
            color: #171B17;
            padding: 1.8mm;
            border-bottom: 0.2mm solid #E4E2DC;
            vertical-align: top;
        }
        .standings-table {
            width: 170mm;
            margin: 4mm auto 0;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .standings-table td {
            width: 50%;
            border: 1px solid #E4E2DC;
            padding: 3mm;
            vertical-align: top;
        }
        .standing-series {
            font-family: 'Saira Condensed', DejaVu Sans, sans-serif;
            font-weight: 700;
            font-size: 11pt;
            color: #006838;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .standing-line {
            margin-top: 1.8mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.8pt;
            color: #171B17;
        }
        .standing-line span {
            color: #6C756E;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 6pt;
        }
        .card-verify {
            width: 90mm;
            margin: 4mm auto 0;
            padding: 4mm;
            text-align: center;
        }
        .qr-chip {
            display: inline-block;
            padding: 2.5mm;
            border: 0.5mm solid #C9971C;
            background: #FFFFFF;
        }
        .qr-chip img {
            display: block;
            width: 20mm;
            height: 20mm;
        }
        .verify-label {
            margin-top: 2.5mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6pt;
            color: #006838;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .verify-url {
            margin-top: 1.2mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 5.8pt;
            color: #6C756E;
            word-wrap: break-word;
        }
        .footer {
            position: fixed;
            bottom: 12mm;
            left: 30mm;
            right: 30mm;
            border-top: 0.25mm solid #E4E2DC;
            padding-top: 2.5mm;
            text-align: center;
            z-index: 2;
        }
        .footer-line {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400;
            font-size: 5.8pt;
            color: #6C756E;
            line-height: 1.5;
        }
    </style>
</head>
<body>
@php
    $matchRows = $scores->take(12);
@endphp
    <div class="page">
        <img class="frame" src="{{ $frameBase64 }}" alt="">

        <div class="content">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="text-align: center;">
                        <img class="logo" src="{{ $logoBase64 }}" alt="SAPRF" style="width: 70mm;">
                    </td>
                </tr>
            </table>

            <table style="width: 70mm; margin: 3.5mm auto 0; border-collapse: collapse;">
                <tr>
                    <td style="width: 31mm; border-bottom: 0.3mm solid #C9971C;"></td>
                    <td style="width: 8mm; text-align: center; vertical-align: middle;">
                        <div style="width: 3mm; height: 3mm; background: #C9971C; margin: 0 auto;"></div>
                    </td>
                    <td style="width: 31mm; border-bottom: 0.3mm solid #C9971C;"></td>
                </tr>
            </table>

            <div class="title">Activity Report</div>
            <div class="subtitle">{{ $season }} Season · Official Activity Record</div>

            <div class="card card-prepared">
                <div class="eyebrow">Prepared for</div>
                <div class="member-name">{{ $user->name }}</div>
                <div class="status-chip {{ $chipMuted ? 'is-muted' : '' }}">{{ $statusLabel }}</div>
            </div>

            <table class="summary-table">
                <tr>
                    <td>
                        <div class="summary-label">SAPRF No</div>
                        <div class="summary-value">{{ $membership->saprf_number ?: '—' }}</div>
                    </td>
                    <td>
                        <div class="summary-label">Province</div>
                        <div class="summary-value">{{ $user->province?->name ?? '—' }}</div>
                    </td>
                    <td>
                        <div class="summary-label">Valid Until</div>
                        <div class="summary-value">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</div>
                    </td>
                    <td>
                        <div class="summary-label">Season</div>
                        <div class="summary-value">{{ $season }}</div>
                    </td>
                </tr>
            </table>

            <table class="stats-table">
                <tr>
                    <td>
                        <div class="stat-value">{{ $stats['total'] }}</div>
                        <div class="stat-label">Matches</div>
                    </td>
                    <td>
                        <div class="stat-value">{{ $stats['prs'] }}</div>
                        <div class="stat-label">PRS</div>
                    </td>
                    <td>
                        <div class="stat-value">{{ $stats['pr22'] }}</div>
                        <div class="stat-label">PR22</div>
                    </td>
                </tr>
            </table>

            <div class="card card-history">
                <div class="section-header"><span>Match History</span></div>
                @if($scores->isEmpty())
                    <div class="empty">No completed matches found for the {{ $season }} season.</div>
                @else
                    <table class="match-table">
                        <thead>
                            <tr>
                                <th style="width: 22mm;">Date</th>
                                <th style="width: 58mm;">Match</th>
                                <th style="width: 28mm;">Division</th>
                                <th class="num" style="width: 22mm;">Score</th>
                                <th class="num" style="width: 20mm;">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($matchRows as $score)
                                <tr>
                                    <td>{{ $score->match_date?->format('d M Y') ?? '—' }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($score->match->name ?? '—', 26) }}</td>
                                    <td>{{ $score->division->name ?? 'Open' }}</td>
                                    <td class="num">{{ number_format((float) $score->raw_score, 0) }}</td>
                                    <td class="num">{{ number_format((float) $score->normalized_score, 1) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if($includeStandings && count($standingsSummary) > 0)
                <div class="card" style="width: 170mm; margin: 4mm auto 0; overflow: hidden;">
                    <div class="section-header-gold"><span>Season Standings</span></div>
                    <table class="standings-table" style="width: 100%; margin: 0;">
                        <tr>
                            @foreach($standingsSummary as $standing)
                                <td>
                                    <div class="standing-series">{{ $standing['series'] }}</div>
                                    <div class="standing-line"><span>Overall rank</span> #{{ $standing['overall_rank'] }}</div>
                                    <div class="standing-line"><span>Points</span> {{ number_format((float) $standing['overall_points'], 2) }}</div>
                                    @if($standing['division_name'])
                                        <div class="standing-line"><span>{{ $standing['division_name'] }}</span> #{{ $standing['division_rank'] }}</div>
                                    @endif
                                </td>
                            @endforeach
                            @if(count($standingsSummary) === 1)
                                <td></td>
                            @endif
                        </tr>
                    </table>
                </div>
            @endif

            <div class="card card-verify">
                <div class="qr-chip">
                    <img src="{{ $qrBase64 }}" alt="QR">
                </div>
                <div class="verify-label">Scan to verify membership</div>
                <div class="verify-url">{{ $verifyUrl }}</div>
            </div>
        </div>

        <div class="footer">
            <div class="footer-line">This activity report is electronically generated and verifiable via the QR code. It may be submitted in support of dedicated status or firearm licence applications.</div>
            <div class="footer-line">Generated {{ $generatedDate }} · {{ $generatedTime }} SAST</div>
        </div>
    </div>
</body>
</html>
