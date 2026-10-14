<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $is_tax_invoice ? 'Tax Invoice' : 'Invoice' }} {{ $invoice_number }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 12px; color: #1c1917; margin: 32px 36px; }
        .header { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .header td { vertical-align: middle; padding: 0; }
        .header td.logo-cell { width: 55%; }
        .header td.title-cell { width: 45%; text-align: right; }
        .logo { max-width: 180px; max-height: 56px; }
        .doc-title { font-size: 26px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; color: #1c1917; margin: 0 0 2px; }
        .doc-subtitle { font-size: 11px; color: #78716c; margin: 0 0 24px; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .meta-table td { vertical-align: top; padding: 0; }
        .business-block { width: 55%; }
        .business-name { font-size: 15px; font-weight: 700; color: #1c1917; margin-bottom: 4px; }
        .business-line { font-size: 11px; color: #44403c; line-height: 1.5; white-space: pre-line; }
        .invoice-meta { width: 45%; text-align: right; font-size: 11px; color: #44403c; }
        .invoice-meta .label { color: #78716c; text-transform: uppercase; font-size: 9px; letter-spacing: 0.6px; margin-bottom: 2px; }
        .invoice-meta .value { font-size: 12px; font-weight: 600; color: #1c1917; margin-bottom: 10px; }
        .invoice-meta .value.mono { font-family: 'Courier New', monospace; }
        .section-heading { font-size: 10px; text-transform: uppercase; letter-spacing: 0.6px; color: #78716c; margin: 16px 0 6px; }
        .bill-to { border: 1px solid #e7e5e4; border-radius: 4px; padding: 10px 12px; margin-bottom: 24px; }
        .bill-to .name { font-size: 13px; font-weight: 600; color: #1c1917; }
        .bill-to .line { font-size: 11px; color: #57534e; margin-top: 2px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th { background: #f5f5f4; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #78716c; padding: 8px 10px; border-bottom: 1px solid #d6d3d1; }
        table.items th.right { text-align: right; }
        table.items td { padding: 10px; border-bottom: 1px solid #f5f5f4; vertical-align: top; font-size: 12px; color: #1c1917; }
        table.items td.right { text-align: right; }
        .detail { color: #78716c; font-size: 10px; margin-top: 2px; }
        .totals { width: 60%; margin-left: 40%; margin-top: 12px; }
        .totals td { padding: 5px 10px; font-size: 11px; }
        .totals td.label { text-align: right; color: #57534e; }
        .totals td.value { text-align: right; font-weight: 600; color: #1c1917; width: 110px; }
        .totals tr.total td { border-top: 2px solid #1c1917; font-size: 14px; font-weight: 700; padding-top: 8px; }
        .footer { margin-top: 36px; padding-top: 10px; border-top: 1px solid #e7e5e4; font-size: 9.5px; color: #78716c; line-height: 1.5; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #ecfdf5; color: #065f46; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td class="logo-cell">
                @if($logo_data_uri)
                    <img src="{{ $logo_data_uri }}" alt="SAPRF" class="logo">
                @endif
            </td>
            <td class="title-cell">
                <h1 class="doc-title">{{ $is_tax_invoice ? 'Tax Invoice' : 'Invoice' }}</h1>
                <p class="doc-subtitle">
                    <span class="pill">Paid</span>
                    &nbsp;Issued {{ $issued_at->format('d F Y') }}
                </p>
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td class="business-block">
                <div class="business-name">{{ $business['name'] }}</div>
                @if($business['address'])
                    <div class="business-line">{{ $business['address'] }}</div>
                @endif
                @if($business['contact_email'])
                    <div class="business-line">{{ $business['contact_email'] }}</div>
                @endif
                @if($business['registration_number'])
                    <div class="business-line">Reg&nbsp;No: {{ $business['registration_number'] }}</div>
                @endif
                @if($business['vat_number'])
                    <div class="business-line">VAT&nbsp;No: {{ $business['vat_number'] }}</div>
                @endif
            </td>
            <td class="invoice-meta">
                <div class="label">Invoice No.</div>
                <div class="value mono">{{ $invoice_number }}</div>
                <div class="label">Invoice Date</div>
                <div class="value">{{ $issued_at->format('d M Y') }}</div>
                <div class="label">Payment Method</div>
                <div class="value">{{ $payment_method }}</div>
                @if($payment->gateway_payment_id)
                    <div class="label">PayFast Reference</div>
                    <div class="value mono">{{ $payment->gateway_payment_id }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="section-heading">Bill To</div>
    <div class="bill-to">
        <div class="name">{{ $bill_to['name'] }}</div>
        @if($bill_to['email'])
            <div class="line">{{ $bill_to['email'] }}</div>
        @endif
        @if($bill_to['saprf_number'])
            <div class="line">SAPRF Member #{{ $bill_to['saprf_number'] }}</div>
        @endif
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div><strong>{{ $line_item['description'] }}</strong></div>
                    @if($line_item['detail'])
                        <div class="detail">{{ $line_item['detail'] }}</div>
                    @endif
                </td>
                <td class="right">R&nbsp;{{ number_format($totals['total'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        @if($is_tax_invoice)
            <tr>
                <td class="label">Subtotal (excl. VAT)</td>
                <td class="value">R&nbsp;{{ number_format($totals['subtotal'], 2) }}</td>
            </tr>
            <tr>
                <td class="label">VAT @ 15%</td>
                <td class="value">R&nbsp;{{ number_format($totals['vat'], 2) }}</td>
            </tr>
        @endif
        <tr class="total">
            <td class="label">Total paid</td>
            <td class="value">R&nbsp;{{ number_format($totals['total'], 2) }}</td>
        </tr>
    </table>

    <div class="footer">
        @if($footer_notes)
            <p>{{ $footer_notes }}</p>
        @endif
        <p>
            Generated {{ now()->format('d M Y H:i') }} &middot;
            {{ $business['name'] }}
            @if($business['contact_email'])
                &middot; {{ $business['contact_email'] }}
            @endif
        </p>
    </div>
</body>
</html>
