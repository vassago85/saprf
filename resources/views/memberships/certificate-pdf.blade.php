<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        @page { margin: 0; }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #1c1917;
            background: #fff;
        }

        .certificate {
            width: 100%;
            min-height: 100vh;
            padding: 50px 60px;
            position: relative;
        }

        .border-frame {
            border: 3px solid #166534;
            border-radius: 4px;
            padding: 40px 50px;
            min-height: calc(100vh - 100px);
            position: relative;
        }

        .border-inner {
            border: 1px solid #bbf7d0;
            border-radius: 2px;
            padding: 35px 40px;
            min-height: calc(100vh - 190px);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .federation-name {
            font-size: 14px;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #166534;
            margin-bottom: 6px;
        }

        .title {
            font-size: 32px;
            font-weight: bold;
            color: #166534;
            margin-bottom: 4px;
        }

        .subtitle {
            font-size: 12px;
            color: #78716c;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .divider {
            width: 80px;
            height: 3px;
            background: #f59e0b;
            margin: 25px auto;
        }

        .certify-text {
            text-align: center;
            font-size: 13px;
            color: #57534e;
            margin-bottom: 20px;
        }

        .member-name {
            text-align: center;
            font-size: 30px;
            font-weight: bold;
            color: #1c1917;
            margin-bottom: 6px;
            padding: 10px 0;
        }

        .member-subtitle {
            text-align: center;
            font-size: 13px;
            color: #78716c;
            margin-bottom: 30px;
        }

        .details-table {
            width: 70%;
            margin: 0 auto 30px;
            border-collapse: collapse;
        }

        .details-table td {
            padding: 10px 15px;
            font-size: 13px;
        }

        .details-table .label {
            color: #78716c;
            text-align: right;
            width: 45%;
            font-weight: normal;
        }

        .details-table .value {
            color: #1c1917;
            font-weight: bold;
            text-align: left;
        }

        .details-table .saprf-number {
            font-family: 'Courier New', monospace;
            font-size: 16px;
            color: #166534;
        }

        .divider-thin {
            width: 60%;
            height: 1px;
            background: #e7e5e4;
            margin: 15px auto;
        }

        .qr-section {
            text-align: center;
            margin-top: 30px;
        }

        .qr-section img {
            width: 120px;
            height: 120px;
        }

        .qr-label {
            font-size: 9px;
            color: #a8a29e;
            margin-top: 6px;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #e7e5e4;
        }

        .footer-text {
            font-size: 9px;
            color: #a8a29e;
            line-height: 1.6;
        }

        .gold-accent {
            color: #f59e0b;
        }

        .green-accent {
            color: #166534;
        }

        .status-badge {
            display: inline-block;
            background: #dcfce7;
            color: #166534;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 4px 16px;
            border-radius: 12px;
        }
    </style>
</head>
<body>
    <div class="certificate">
        <div class="border-frame">
            <div class="border-inner">
                <div class="header">
                    <div class="federation-name">South African Precision Rifle Federation</div>
                    <div class="title">Membership Certificate</div>
                    <div class="subtitle">Official Membership Documentation</div>
                </div>

                <div class="divider"></div>

                <div class="certify-text">This is to certify that</div>

                <div class="member-name">{{ $user->name }}</div>
                <div class="member-subtitle">
                    <span class="status-badge">Active Member</span>
                </div>

                <div class="divider-thin"></div>

                <table class="details-table">
                    <tr>
                        <td class="label">SAPRF Number</td>
                        <td class="value saprf-number">{{ $membership->saprf_number }}</td>
                    </tr>
                    <tr>
                        <td class="label">Membership Type</td>
                        <td class="value">{{ ucfirst($membership->membership_type ?? 'Standard') }}</td>
                    </tr>
                    <tr>
                        <td class="label">Member Since</td>
                        <td class="value">{{ $membership->start_date?->format('d M Y') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Valid Until</td>
                        <td class="value green-accent">{{ $membership->expiry_date?->format('d M Y') ?? '—' }}</td>
                    </tr>
                    @if($user->province)
                    <tr>
                        <td class="label">Province</td>
                        <td class="value">{{ $user->province->name }}</td>
                    </tr>
                    @endif
                </table>

                <div class="qr-section">
                    <img src="{{ $qrBase64 }}" alt="QR Verification">
                    <div class="qr-label">Scan to verify membership status</div>
                </div>

                <div class="footer">
                    <div class="footer-text">
                        This certificate is electronically generated and verifiable via the QR code above.<br>
                        Verification URL: {{ $verifyUrl }}<br>
                        Generated on {{ now()->format('d M Y \a\t H:i') }} SAST
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
