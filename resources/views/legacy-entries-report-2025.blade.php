<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SAPRF Match Entries Report — {{ $member['MembershipNo'] }} — 2025</title>
    @include('memberships.partials.certificate-fonts')
    <style>
        @page { margin: 0; size: A4 portrait; }
        * { margin: 0; padding: 0; }
        body {
            margin: 0; padding: 0; background: #FFFFFF; color: #171B17;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
        }
        .page { position: relative; width: 210mm; height: 297mm; overflow: hidden; background: #FFFFFF; }
        .frame { position: fixed; top: 0; left: 0; width: 210mm; height: 297mm; z-index: 0; }
        .content { position: relative; z-index: 1; padding: 16mm 16mm 22mm; }
        .logo { display: block; width: 70mm; margin: 0 auto; }
        .title {
            font-family: 'Saira Condensed', DejaVu Sans, sans-serif;
            font-weight: 700; font-size: 24pt; color: #006838;
            letter-spacing: 0.06em; text-align: center;
            text-transform: uppercase; margin-top: 3.5mm;
        }
        .subtitle {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400; font-size: 6.2pt; color: #A57B12;
            letter-spacing: 0.2em; text-align: center;
            text-transform: uppercase; margin-top: 1.8mm;
        }
        .rule {
            width: 70mm; margin: 3.5mm auto 0; border-collapse: collapse;
        }
        .rule td { border-bottom: 0.3mm solid #C9971C; }
        .rule .dot { width: 3mm; height: 3mm; background: #C9971C; margin: 0 auto; }
        .card { border: 1px solid #E4E2DC; border-radius: 2mm; background: rgba(255, 255, 255, 0.75); }
        .card-prepared { width: 170mm; margin: 4.5mm auto 0; padding: 5mm 6mm; text-align: center; }
        .eyebrow {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400; font-size: 6.6pt; color: #6C756E;
            letter-spacing: 0.18em; text-transform: uppercase;
        }
        .member-name {
            font-family: 'Saira', DejaVu Sans, sans-serif;
            font-weight: 600; font-size: 24pt; color: #171B17;
            margin-top: 2mm; white-space: nowrap;
        }
        .status-chip {
            display: inline-block; margin-top: 2.5mm;
            padding: 1.2mm 3.8mm; border: 0.45mm solid #C9971C;
            border-radius: 10mm; background: #FBF6EA;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.6pt; color: #006838;
            letter-spacing: 0.16em; text-transform: uppercase;
        }
        .summary-table { width: 170mm; margin: 4mm auto 0; border-collapse: collapse; table-layout: fixed; }
        .summary-table td { width: 25%; border: 1px solid #E4E2DC; padding: 3mm 2mm; text-align: center; vertical-align: top; }
        .summary-label {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 5.8pt; color: #6C756E; letter-spacing: 0.14em; text-transform: uppercase;
        }
        .summary-value {
            margin-top: 1.2mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 600; font-size: 8pt; color: #171B17;
        }
        .stats-table { width: 170mm; margin: 4mm auto 0; border-collapse: collapse; table-layout: fixed; }
        .stats-table td { width: 33.33%; border: 1px solid #E4E2DC; padding: 4mm 2mm; text-align: center; vertical-align: middle; }
        .stat-value {
            font-family: 'Saira Condensed', DejaVu Sans, sans-serif;
            font-weight: 700; font-size: 22pt; color: #006838; line-height: 1;
        }
        .stat-label {
            margin-top: 1.5mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6pt; color: #6C756E; letter-spacing: 0.16em; text-transform: uppercase;
        }
        .card-history { width: 170mm; margin: 4mm auto 0; overflow: hidden; }
        .section-header { background: #006838; padding: 1.6mm 4mm; text-align: center; }
        .section-header span {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.2pt; color: #FFFFFF; letter-spacing: 0.2em; text-transform: uppercase;
        }
        .match-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .match-table th {
            background: #F4F3EF;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400; font-size: 6pt; color: #6C756E;
            letter-spacing: 0.12em; text-transform: uppercase;
            text-align: left; padding: 2mm 1.8mm;
            border-bottom: 0.2mm solid #E4E2DC;
        }
        .match-table th.num, .match-table td.num { text-align: right; }
        .match-table td {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.6pt; color: #171B17;
            padding: 1.8mm; border-bottom: 0.2mm solid #E4E2DC; vertical-align: top;
        }
        .footer {
            position: fixed; bottom: 12mm; left: 20mm; right: 20mm;
            border-top: 0.25mm solid #E4E2DC; padding-top: 2.5mm;
            text-align: center; z-index: 2;
        }
        .footer-line {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400; font-size: 5.8pt; color: #6C756E; line-height: 1.5;
        }
    </style>
</head>
<body>
<div class="page">
    <img class="frame" src="{{ $frameBase64 }}" alt="">

    <div class="content">
        <img class="logo" src="{{ $logoBase64 }}" alt="SAPRF">

        <table class="rule">
            <tr>
                <td style="width: 31mm;"></td>
                <td style="width: 8mm;"><div class="dot"></div></td>
                <td style="width: 31mm;"></td>
            </tr>
        </table>

        <div class="title">Match Entries Report</div>
        <div class="subtitle">Calendar Year 2025 · Official Entries Record</div>

        <div class="card card-prepared">
            <div class="eyebrow">Prepared for</div>
            <div class="member-name">{{ $member['FirstName'] }} {{ $member['Surname'] }}</div>
            <div class="status-chip">{{ strtoupper($member['MembershipStatus']) }} MEMBER</div>
        </div>

        <table class="summary-table">
            <tr>
                <td>
                    <div class="summary-label">SAPRF No</div>
                    <div class="summary-value">{{ $member['MembershipNo'] }}</div>
                </td>
                <td>
                    <div class="summary-label">Primary Club</div>
                    <div class="summary-value">{{ $member['PrimaryClub'] ?: '—' }}</div>
                </td>
                <td>
                    <div class="summary-label">Province</div>
                    <div class="summary-value">{{ $member['HomeProvince'] ?: '—' }}</div>
                </td>
                <td>
                    <div class="summary-label">Valid Until</div>
                    <div class="summary-value">{{ \Carbon\Carbon::parse($member['Expiry'])->format('d M Y') }}</div>
                </td>
            </tr>
        </table>

        <table class="stats-table">
            <tr>
                <td>
                    <div class="stat-value">{{ count($entries) }}</div>
                    <div class="stat-label">Entries in 2025</div>
                </td>
                <td>
                    <div class="stat-value">{{ collect($entries)->where('Level','National')->count() }}</div>
                    <div class="stat-label">National</div>
                </td>
                <td>
                    <div class="stat-value">{{ collect($entries)->where('Level','Provincial')->count() }}</div>
                    <div class="stat-label">Provincial</div>
                </td>
            </tr>
        </table>

        <div class="card card-history">
            <div class="section-header"><span>Match Entry History · 2025</span></div>
            <table class="match-table">
                <thead>
                    <tr>
                        <th style="width: 20mm;">Date</th>
                        <th style="width: 62mm;">Match</th>
                        <th style="width: 32mm;">Venue</th>
                        <th style="width: 16mm;">Level</th>
                        <th style="width: 14mm;">Div</th>
                        <th class="num" style="width: 12mm;">Score</th>
                        <th class="num" style="width: 14mm;">Hit %</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entries as $e)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($e['MatchDate'])->format('d M Y') }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($e['EventTitle'], 44) }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($e['Venue'], 22) }}</td>
                            <td>{{ $e['Level'] }}</td>
                            <td>{{ $e['Division'] }}</td>
                            <td class="num">{{ $e['TotalScore'] }}</td>
                            <td class="num">{{ number_format((float)$e['HitPercent'], 1) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="eyebrow" style="margin-top: 6mm; text-align: center;">
            Source: SAPRF Admin System · mem.MatchEntrant · member #{{ $member['MembershipNo'] }}
        </div>
    </div>

    <div class="footer">
        <div class="footer-line">This report lists all SAPRF-sanctioned match entries recorded against SAPRF #{{ $member['MembershipNo'] }} between 01 January 2025 and 31 December 2025.</div>
        <div class="footer-line">It may be submitted in support of dedicated status or firearm licence applications. Generated {{ $generatedAt }} SAST.</div>
    </div>
</div>
</body>
</html>
