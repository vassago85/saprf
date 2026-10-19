<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SAPRF Payout Statement — {{ $payout->reference }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1c1917;
            margin: 24px 32px;
        }
        h1, h2, h3 { margin: 0; }
        /*
         * DomPDF has only partial flexbox support, so the header is a
         * single-row table with two cells (brand left, reference right).
         * The bottom border on the wrapper div gives the same visual rule
         * flex layout did without relying on `display: flex`.
         */
        .header {
            border-bottom: 2px solid #065f46;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }
        .header table { width: 100%; border-collapse: collapse; margin: 0; }
        .header td { padding: 0; vertical-align: bottom; border: 0; }
        .header td.right { text-align: right; }
        .brand { font-size: 20px; font-weight: 800; color: #065f46; letter-spacing: 1px; }
        .brand-sub { font-size: 10px; color: #57534e; margin-top: 2px; }
        .ref { font-size: 11px; color: #44403c; }
        .ref strong { display: block; font-size: 14px; color: #0c0a09; }
        .match {
            background: #f5f5f4;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: 11px;
        }
        .match strong { font-size: 13px; color: #0c0a09; }
        .match .meta { color: #57534e; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 6px 8px; text-align: left; font-size: 10.5px; }
        th { background: #f5f5f4; color: #44403c; text-transform: uppercase; font-size: 9px; letter-spacing: 0.4px; }
        tbody tr { border-bottom: 1px solid #e7e5e4; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .totals { background: #ecfdf5; font-weight: 700; color: #064e3b; }
        .excluded td { color: #a16207; }
        .summary {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }
        .summary .row { display: table-row; }
        .summary .cell { display: table-cell; padding: 3px 0; font-size: 11px; }
        .summary .label { color: #57534e; }
        .summary .value { text-align: right; font-variant-numeric: tabular-nums; }
        .summary .net { font-weight: 800; color: #065f46; font-size: 13px; padding-top: 6px; border-top: 1px solid #e7e5e4; }
        .section-title {
            font-size: 10px;
            color: #44403c;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-top: 18px;
            margin-bottom: 4px;
            font-weight: 700;
        }
        .foot {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e7e5e4;
            font-size: 9px;
            color: #78716c;
        }
    </style>
</head>
<body>

    <div class="header">
        <table>
            <tr>
                <td>
                    <div class="brand">SAPRF</div>
                    <div class="brand-sub">South African Precision Rifle Federation</div>
                </td>
                <td class="right">
                    <div class="ref">
                        Payout statement
                        <strong>{{ $payout->reference }}</strong>
                        Generated {{ $generatedAt->format('d M Y H:i') }} &middot; v{{ $payout->statement_version }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="match">
        <strong>{{ $match['name'] ?? ($payout->match?->name ?? 'Match') }}</strong>
        <div class="meta">
            @if($match['match_date'] ?? null) {{ \Carbon\Carbon::parse($match['match_date'])->format('d M Y') }} &middot; @endif
            {{ ucfirst($match['match_type'] ?? ($payout->match?->match_type ?? 'Match')) }}
            @if($match['series_level'] ?? null) &middot; {{ ucfirst($match['series_level']) }} @endif
            &middot; MD: {{ $match['md_name'] ?? ($payout->payeeUser?->name ?? 'Unknown') }}
        </div>
    </div>

    <div class="section-title">Summary</div>
    <div class="summary">
        <div class="row">
            <div class="cell label">Entries paid</div>
            <div class="cell value">{{ $summary['paid_registrations'] ?? $paidItems->count() }}</div>
        </div>
        <div class="row">
            <div class="cell label">Gross revenue</div>
            <div class="cell value">R{{ number_format((float) ($summary['gross_revenue'] ?? 0), 2) }}</div>
        </div>
        <div class="row">
            <div class="cell label">Non-member surcharge (SAPRF)</div>
            <div class="cell value">-R{{ number_format((float) ($summary['surcharges'] ?? 0), 2) }}</div>
        </div>
        <div class="row">
            <div class="cell label">SAPRF levy</div>
            <div class="cell value">-R{{ number_format((float) ($summary['saprf_fees'] ?? 0), 2) }}</div>
        </div>
        <div class="row">
            <div class="cell label">Platform fee</div>
            <div class="cell value">-R{{ number_format((float) ($summary['platform_fees'] ?? 0), 2) }}</div>
        </div>
        <div class="row">
            <div class="cell label">Gateway fees</div>
            <div class="cell value">-R{{ number_format((float) ($summary['gateway_fees'] ?? 0), 2) }}</div>
        </div>
        <div class="row">
            <div class="cell net label">Net due to MD</div>
            <div class="cell net value">R{{ number_format((float) ($summary['md_net'] ?? $payout->net_amount), 2) }}</div>
        </div>
        <div class="row">
            <div class="cell label">Paid to date</div>
            <div class="cell value">R{{ number_format((float) $payout->paid_amount, 2) }}</div>
        </div>
        <div class="row">
            <div class="cell label">Outstanding</div>
            <div class="cell value">R{{ number_format((float) $outstanding, 2) }}</div>
        </div>
    </div>

    @if($payments->isNotEmpty())
    <div class="section-title">Payment history</div>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th>Bank reference</th>
                <th class="num">Amount (R)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payments as $tx)
                <tr>
                    <td>{{ $tx->created_at->format('d M Y') }}</td>
                    <td>{{ $tx->description }}</td>
                    <td>{{ data_get($tx, 'meta.payment_reference') ?? ($payout->payment_reference ?? '—') }}</td>
                    <td class="num">R{{ number_format((float) $tx->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="section-title">Paid entries</div>
    <table>
        <thead>
            <tr>
                <th>Shooter</th>
                <th>Category</th>
                <th>Method</th>
                <th class="num">Fee</th>
                <th class="num">Surcharge</th>
                <th class="num">SAPRF</th>
                <th class="num">Platform</th>
                <th class="num">Gateway</th>
                <th class="num">MD Net</th>
            </tr>
        </thead>
        <tbody>
            @forelse($paidItems as $item)
                <tr>
                    <td>{{ $item->shooter_name }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', (string) $item->membership_fee_category)) }}</td>
                    <td>{{ $item->methodLabel() }}</td>
                    <td class="num">R{{ number_format((float) $item->gross_amount, 2) }}</td>
                    <td class="num">R{{ number_format((float) $item->surcharge_amount, 2) }}</td>
                    <td class="num">R{{ number_format((float) $item->saprf_fee, 2) }}</td>
                    <td class="num">R{{ number_format((float) $item->platform_fee, 2) }}</td>
                    <td class="num">R{{ number_format((float) $item->gateway_fee, 2) }}</td>
                    <td class="num">R{{ number_format((float) $item->net_amount, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="9">No paid entries on this statement.</td></tr>
            @endforelse
            <tr class="totals">
                <td colspan="3">Totals ({{ $paidItems->count() }} paid)</td>
                <td class="num">R{{ number_format((float) ($summary['gross_revenue'] ?? 0), 2) }}</td>
                <td class="num">R{{ number_format((float) ($summary['surcharges'] ?? 0), 2) }}</td>
                <td class="num">R{{ number_format((float) ($summary['saprf_fees'] ?? 0), 2) }}</td>
                <td class="num">R{{ number_format((float) ($summary['platform_fees'] ?? 0), 2) }}</td>
                <td class="num">R{{ number_format((float) ($summary['gateway_fees'] ?? 0), 2) }}</td>
                <td class="num">R{{ number_format((float) ($summary['md_net'] ?? $payout->net_amount), 2) }}</td>
            </tr>
        </tbody>
    </table>

    @if($excludedItems->isNotEmpty())
        <div class="section-title">Not included in payout</div>
        <table>
            <thead>
                <tr>
                    <th>Shooter</th>
                    <th>Category</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($excludedItems as $item)
                    <tr class="excluded">
                        <td>{{ $item->shooter_name }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', (string) $item->membership_fee_category)) }}</td>
                        <td>{{ ucfirst((string) ($item->excluded_reason ?: $item->payment_status)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="foot">
        Gateway fees are the actual amount PayFast reports back on settlement — not a flat estimate.
        Non-member surcharges are retained by SAPRF and shown as a deduction from gross revenue.
        Pending, failed, refunded, and waived entries do not contribute to this payout.
    </div>

</body>
</html>
