<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @page { margin: 30px 40px; }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #1c1917;
            font-size: 11px;
            line-height: 1.4;
        }

        .header {
            text-align: center;
            padding-bottom: 15px;
            border-bottom: 2px solid #166534;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 20px;
            color: #166534;
            margin-bottom: 2px;
        }

        .header .subtitle {
            font-size: 10px;
            color: #78716c;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .header .report-title {
            font-size: 16px;
            font-weight: bold;
            color: #1c1917;
            margin-top: 10px;
        }

        .member-details {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: #fafaf9;
            border: 1px solid #e7e5e4;
        }

        .member-details td {
            padding: 7px 12px;
            font-size: 11px;
            border-bottom: 1px solid #e7e5e4;
        }

        .member-details .label {
            color: #78716c;
            width: 30%;
            font-weight: normal;
        }

        .member-details .value {
            color: #1c1917;
            font-weight: bold;
        }

        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #166534;
            margin: 20px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #d6d3d1;
        }

        .match-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        .match-table th {
            background: #166534;
            color: #fff;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 8px;
            text-align: left;
        }

        .match-table td {
            padding: 5px 8px;
            font-size: 10px;
            border-bottom: 1px solid #e7e5e4;
        }

        .match-table tr:nth-child(even) td {
            background: #fafaf9;
        }

        .match-table .numeric {
            text-align: right;
            font-family: 'Courier New', monospace;
        }

        .standings-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        .standings-table th {
            background: #f59e0b;
            color: #fff;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 8px;
            text-align: left;
        }

        .standings-table td {
            padding: 5px 8px;
            font-size: 10px;
            border-bottom: 1px solid #e7e5e4;
        }

        .standings-table .numeric {
            text-align: right;
            font-family: 'Courier New', monospace;
        }

        .summary-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 10px 15px;
            margin-bottom: 20px;
        }

        .summary-box p {
            font-size: 11px;
            color: #166534;
        }

        .summary-box .big {
            font-size: 18px;
            font-weight: bold;
        }

        .footer {
            margin-top: 25px;
            padding-top: 12px;
            border-top: 1px solid #d6d3d1;
            text-align: center;
        }

        .footer-row {
            display: table;
            width: 100%;
        }

        .footer-left {
            display: table-cell;
            width: 70%;
            vertical-align: middle;
            text-align: left;
        }

        .footer-right {
            display: table-cell;
            width: 30%;
            vertical-align: middle;
            text-align: right;
        }

        .footer-text {
            font-size: 8px;
            color: #a8a29e;
            line-height: 1.6;
        }

        .footer-right img {
            width: 80px;
            height: 80px;
        }

        .badge {
            display: inline-block;
            font-size: 9px;
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 8px;
        }

        .badge-active {
            background: #dcfce7;
            color: #166534;
        }

        .badge-expired {
            background: #fef2f2;
            color: #991b1b;
        }

        .badge-lapsed {
            background: #fffbeb;
            color: #92400e;
        }

        .no-data {
            text-align: center;
            padding: 20px;
            color: #a8a29e;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>SAPRF</h1>
        <div class="subtitle">South African Precision Rifle Federation</div>
        <div class="report-title">Activity Report &mdash; {{ $season }} Season</div>
    </div>

    {{-- Member Details --}}
    <table class="member-details">
        <tr>
            <td class="label">Member Name</td>
            <td class="value">{{ $user->name }}</td>
            <td class="label">SAPRF Number</td>
            <td class="value" style="font-family: 'Courier New', monospace;">{{ $membership->saprf_number }}</td>
        </tr>
        <tr>
            <td class="label">Status</td>
            <td class="value">
                @if($membership->status === 'active' && $membership->payment_status === 'paid')
                    <span class="badge badge-active">Active</span>
                @elseif(in_array($membership->status, ['expired', 'lapsed']))
                    <span class="badge badge-{{ $membership->status }}">{{ ucfirst($membership->status) }}</span>
                @else
                    {{ ucfirst($membership->status) }}
                @endif
            </td>
            <td class="label">Expires</td>
            <td class="value">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</td>
        </tr>
        @if($user->province)
        <tr>
            <td class="label">Province</td>
            <td class="value" colspan="3">{{ $user->province->name }}</td>
        </tr>
        @endif
    </table>

    {{-- Match Summary --}}
    <div class="summary-box">
        <p><span class="big">{{ $scores->count() }}</span> match{{ $scores->count() !== 1 ? 'es' : '' }} completed in the {{ $season }} season</p>
    </div>

    {{-- Match History --}}
    <div class="section-title">Match History</div>

    @if($scores->isEmpty())
        <div class="no-data">No completed matches found for the {{ $season }} season.</div>
    @else
        <table class="match-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Match</th>
                    <th>Type</th>
                    <th>Level</th>
                    <th>Division</th>
                    <th style="text-align:right">Impacts</th>
                    <th style="text-align:right">% Score</th>
                    <th style="text-align:right">Rank</th>
                </tr>
            </thead>
            <tbody>
                @foreach($scores as $score)
                <tr>
                    <td>{{ $score->match_date?->format('d M Y') ?? '—' }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($score->match->name ?? '—', 30) }}</td>
                    <td>{{ $score->match->match_type ?? '—' }}</td>
                    <td>{{ ucfirst($score->match->series_level ?? '—') }}</td>
                    <td>{{ $score->division->name ?? 'Open' }}</td>
                    <td class="numeric">{{ number_format($score->raw_score, 0) }}</td>
                    <td class="numeric">{{ number_format($score->normalized_score, 2) }}</td>
                    <td class="numeric">{{ $score->overall_rank ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Standings --}}
    @if($includeStandings && count($standingsSummary) > 0)
        <div class="section-title">Season Standings</div>

        @foreach($standingsSummary as $standing)
            <table class="standings-table">
                <thead>
                    <tr>
                        <th colspan="3">{{ $standing['series'] }} Series — {{ $season }}</th>
                    </tr>
                    <tr>
                        <th>Category</th>
                        <th style="text-align:right">Rank</th>
                        <th style="text-align:right">Points</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Overall</strong></td>
                        <td class="numeric">{{ $standing['overall_rank'] }}</td>
                        <td class="numeric">{{ number_format($standing['overall_points'], 2) }}</td>
                    </tr>
                    @if($standing['division_name'])
                    <tr>
                        <td>{{ $standing['division_name'] }}</td>
                        <td class="numeric">{{ $standing['division_rank'] }}</td>
                        <td class="numeric">{{ number_format($standing['division_points'], 2) }}</td>
                    </tr>
                    @endif
                    @foreach($standing['categories'] as $cat)
                    <tr>
                        <td>{{ $cat['name'] }}</td>
                        <td class="numeric">{{ $cat['rank'] }}</td>
                        <td class="numeric">{{ number_format($cat['points'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif

    {{-- Footer with QR --}}
    <div class="footer">
        <div class="footer-row">
            <div class="footer-left">
                <div class="footer-text">
                    This document is electronically generated by the SAPRF platform and is verifiable via the QR code.<br>
                    Verification URL: {{ $verifyUrl }}<br>
                    Generated on {{ now()->format('d M Y \a\t H:i') }} SAST<br><br>
                    This activity report may be submitted in support of dedicated status applications<br>
                    or as proof of activity for firearm licence applications.
                </div>
            </div>
            <div class="footer-right">
                <img src="{{ $qrBase64 }}" alt="QR Verification">
                <div style="font-size: 7px; color: #a8a29e; margin-top: 3px;">Scan to verify</div>
            </div>
        </div>
    </div>
</body>
</html>
