<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SAPRF Membership Certificate — {{ $membership->saprf_number }}</title>
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
            padding: 18mm 16mm 20mm;
        }
        .logo {
            display: block;
            width: 102mm;
        }
        .title {
            font-family: 'Saira Condensed', DejaVu Sans, sans-serif;
            font-weight: 700;
            font-size: 28pt;
            color: #006838;
            letter-spacing: 0.06em;
            text-align: center;
            text-transform: uppercase;
            margin-top: 4mm;
        }
        .subtitle {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400;
            font-size: 6.4pt;
            color: #A57B12;
            letter-spacing: 0.22em;
            text-align: center;
            text-transform: uppercase;
            margin-top: 2mm;
        }
        .card {
            border: 1px solid #E4E2DC;
            border-radius: 2mm;
            background: #FFFFFF;
        }
        .card-certify {
            width: 120mm;
            padding: 6mm 7mm;
            text-align: center;
        }
        .eyebrow {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400;
            font-size: 7pt;
            color: #6C756E;
            letter-spacing: 0.2em;
            text-transform: uppercase;
        }
        .member-name {
            font-family: 'Saira', DejaVu Sans, sans-serif;
            font-weight: 600;
            font-size: {{ $memberNameSize }};
            color: #171B17;
            margin-top: 3mm;
            white-space: nowrap;
        }
        .status-chip {
            display: inline-block;
            margin-top: 3.5mm;
            padding: 1.4mm 4.2mm;
            border: 0.45mm solid #C9971C;
            border-radius: 10mm;
            background: #FBF6EA;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400;
            font-size: 7pt;
            color: #006838;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }
        .status-chip.is-muted {
            border-color: #6C756E;
            color: #6C756E;
            background: #FFFFFF;
        }
        .card-record {
            width: 110mm;
            overflow: hidden;
        }
        .record-header {
            background: #006838;
            padding: 1.6mm 4mm;
            text-align: center;
        }
        .record-header span {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400;
            font-size: 6.2pt;
            color: #FFFFFF;
            letter-spacing: 0.2em;
            text-transform: uppercase;
        }
        .record-body {
            padding: 3mm 5mm 3.5mm;
        }
        .card-verify {
            width: 90mm;
            padding: 5mm;
            text-align: center;
        }
        .center-wrap {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6mm;
        }
        .center-wrap td {
            text-align: center;
            vertical-align: top;
        }
        .qr-chip {
            display: inline-block;
            padding: 3mm;
            border: 0.5mm solid #C9971C;
            background: #FFFFFF;
        }
        .qr-chip img {
            display: block;
            width: 24mm;
            height: 24mm;
        }
        .verify-label {
            margin-top: 3mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400;
            font-size: 6.2pt;
            color: #006838;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }
        .verify-url {
            margin-top: 1.4mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-weight: 400;
            font-size: 6pt;
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
    <div class="page">
        <img class="frame" src="{{ $frameBase64 }}" alt="">

        <div class="content">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="text-align: center;">
                        <img class="logo" src="{{ $logoBase64 }}" alt="SAPRF" style="width: 102mm;">
                    </td>
                </tr>
            </table>

            <table class="center-wrap" style="margin-top: 5mm;">
                <tr>
                    <td>
                        <table style="width: 80mm; margin: 0 auto; border-collapse: collapse;">
                            <tr>
                                <td style="width: 36mm; border-bottom: 0.3mm solid #C9971C;"></td>
                                <td style="width: 8mm; text-align: center; vertical-align: middle;">
                                    <div style="width: 3mm; height: 3mm; background: #C9971C; margin: 0 auto;"></div>
                                </td>
                                <td style="width: 36mm; border-bottom: 0.3mm solid #C9971C;"></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <div class="title">Membership Certificate</div>
            <div class="subtitle">Official Membership Documentation</div>

            <table class="center-wrap">
                <tr>
                    <td align="center">
                        <table class="card card-certify" style="width: 120mm; border-collapse: separate; border-spacing: 0; margin: 0 auto;">
                            <tr>
                                <td style="padding: 6mm 7mm; text-align: center;">
                                    <div class="eyebrow">This is to certify that</div>
                                    <div class="member-name">{{ $user->name }}</div>
                                    <div class="status-chip {{ $chipMuted ? 'is-muted' : '' }}">{{ $statusLabel }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <table class="center-wrap">
                <tr>
                    <td align="center">
                        <table class="card card-record" style="width: 110mm; border-collapse: separate; border-spacing: 0; margin: 0 auto;">
                            <tr>
                                <td class="record-header" style="padding: 1.6mm 4mm; text-align: center; background: #006838;">
                                    <span>Member Record</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="record-body" style="padding: 3mm 5mm 3.5mm;">
                                    <table style="width: 100%; table-layout: fixed; border-collapse: collapse;">
                                        @foreach ($recordRows as $row)
                                            <tr>
                                                <td style="width: 32mm; white-space: nowrap; text-align: left;
                                                           font: 400 7pt 'IBM Plex Mono', DejaVu Sans Mono, monospace; color: #6C756E;
                                                           text-transform: uppercase; letter-spacing: .15em;
                                                           padding: 2.3mm 2.5mm 2.3mm 0; vertical-align: bottom;">{{ $row['label'] }}</td>
                                                <td style="border-bottom: 0.3mm dotted #9aa39d; vertical-align: bottom;"></td>
                                                <td style="width: 36mm; white-space: nowrap; text-align: right;
                                                           font: 600 9pt 'IBM Plex Mono', DejaVu Sans Mono, monospace; color: #171B17;
                                                           padding: 2.3mm 0 2.3mm 2.5mm; vertical-align: bottom;">{{ $row['value'] }}</td>
                                            </tr>
                                        @endforeach
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <table class="center-wrap">
                <tr>
                    <td align="center">
                        <table class="card card-verify" style="width: 90mm; border-collapse: separate; border-spacing: 0; margin: 0 auto;">
                            <tr>
                                <td style="padding: 5mm; text-align: center;">
                                    <div class="qr-chip">
                                        <img src="{{ $qrBase64 }}" alt="QR">
                                    </div>
                                    <div class="verify-label">Scan to verify membership</div>
                                    <div class="verify-url">{{ $verifyUrl }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>

        <div class="footer">
            <div class="footer-line">This certificate is electronically generated and verifiable via the QR code.</div>
            <div class="footer-line">Generated {{ $generatedDate }} · {{ $generatedTime }} SAST</div>
        </div>
    </div>
</body>
</html>
