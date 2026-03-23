<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>SAPRF Financial Report</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 11px; color: #1c1917; margin: 20px; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        h2 { font-size: 14px; color: #44403c; margin-top: 24px; margin-bottom: 8px; border-bottom: 1px solid #e7e5e4; padding-bottom: 4px; }
        .subtitle { font-size: 11px; color: #78716c; margin-bottom: 20px; }
        .summary-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .summary-table td { padding: 6px 10px; border-bottom: 1px solid #f5f5f4; }
        .summary-table td:first-child { color: #57534e; }
        .summary-table td:last-child { text-align: right; font-weight: 600; }
        .match-table { width: 100%; border-collapse: collapse; font-size: 10px; }
        .match-table th { background: #f5f5f4; padding: 6px 8px; text-align: left; font-weight: 600; font-size: 9px; text-transform: uppercase; color: #78716c; border-bottom: 1px solid #d6d3d1; }
        .match-table td { padding: 5px 8px; border-bottom: 1px solid #f5f5f4; }
        .match-table td.right, .match-table th.right { text-align: right; }
        .footer { margin-top: 30px; font-size: 9px; color: #a8a29e; border-top: 1px solid #e7e5e4; padding-top: 6px; }
    </style>
</head>
<body>
    <h1>SAPRF Financial Report</h1>
    <p class="subtitle">
        @if($from && $to)
            Period: {{ $from->format('d M Y') }} — {{ $to->format('d M Y') }}
        @else
            All Time
        @endif
        &nbsp;&middot;&nbsp; Generated: {{ now()->format('d M Y H:i') }}
    </p>

    <h2>Platform Summary</h2>
    <table class="summary-table">
        <tr><td>Gross Income</td><td>R{{ number_format($summary['gross_income'], 2) }}</td></tr>
        <tr><td>Match Revenue</td><td>R{{ number_format($summary['match_revenue']['gross'], 2) }}</td></tr>
        <tr><td>Membership Revenue</td><td>R{{ number_format($summary['membership_revenue']['gross'], 2) }}</td></tr>
        <tr><td>Platform Fees</td><td>R{{ number_format($summary['total_platform_fees'], 2) }}</td></tr>
        <tr><td>SAPRF Fees</td><td>R{{ number_format($summary['total_saprf_fees'], 2) }}</td></tr>
        <tr><td>Gateway Fees</td><td>R{{ number_format($summary['total_gateway_fees'], 2) }}</td></tr>
        <tr><td>Surcharges</td><td>R{{ number_format($summary['total_surcharges'], 2) }}</td></tr>
        <tr><td style="font-weight:700;">Net Revenue (SAPRF)</td><td style="font-weight:700; color:#166534;">R{{ number_format($summary['net_revenue'], 2) }}</td></tr>
        <tr><td>Total MD Payouts</td><td>R{{ number_format($summary['total_md_payouts'], 2) }}</td></tr>
    </table>

    <h2>Revenue by Match</h2>
    <table class="match-table">
        <thead>
            <tr>
                <th>Match</th>
                <th>Date</th>
                <th class="right">Entries</th>
                <th class="right">Gross</th>
                <th class="right">SAPRF</th>
                <th class="right">Platform</th>
                <th class="right">Gateway</th>
                <th class="right">MD Net</th>
            </tr>
        </thead>
        <tbody>
            @foreach($matchBreakdown as $m)
            <tr>
                <td>{{ $m->name }}</td>
                <td>{{ \Carbon\Carbon::parse($m->match_date)->format('d M Y') }}</td>
                <td class="right">{{ $m->entries }}</td>
                <td class="right">R{{ number_format($m->gross, 2) }}</td>
                <td class="right">R{{ number_format($m->saprf_fees, 2) }}</td>
                <td class="right">R{{ number_format($m->platform_fees, 2) }}</td>
                <td class="right">R{{ number_format($m->gateway_fees, 2) }}</td>
                <td class="right">R{{ number_format($m->md_net, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        SAPRF Platform &middot; Financial Report &middot; Confidential
    </div>
</body>
</html>
