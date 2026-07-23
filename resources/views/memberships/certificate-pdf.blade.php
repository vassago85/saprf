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
            padding: 16mm 18mm 0;
        }
        .title {
            font-family: 'Saira Condensed', DejaVu Sans, sans-serif;
            font-weight: 700;
            font-size: 22pt;
            color: #006838;
            letter-spacing: 0.05em;
            text-align: center;
            text-transform: uppercase;
            margin-top: 2.5mm;
            line-height: 1.05;
        }
        .subtitle {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 5.8pt;
            color: #A57B12;
            letter-spacing: 0.18em;
            text-align: center;
            text-transform: uppercase;
            margin-top: 1.2mm;
        }
        .card {
            border: 1px solid #E4E2DC;
            /* DomPDF-safe translucent white (75% opaque) */
            background-color: rgba(255, 255, 255, 0.75);
        }
        .eyebrow {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.4pt;
            color: #6C756E;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .member-name {
            font-family: 'Saira', DejaVu Sans, sans-serif;
            font-weight: 600;
            font-size: {{ $memberNameSize }};
            color: #171B17;
            margin-top: 2mm;
            white-space: nowrap;
            line-height: 1.1;
        }
        .status-chip {
            display: inline-block;
            margin-top: 2.2mm;
            padding: 1mm 3.5mm;
            border: 0.4mm solid #C9971C;
            border-radius: 8mm;
            background-color: rgba(251, 246, 234, 0.75);
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.4pt;
            color: #006838;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .status-chip.is-muted {
            border-color: #6C756E;
            color: #6C756E;
            background-color: rgba(255, 255, 255, 0.75);
        }
        .record-header-text {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 5.8pt;
            color: #FFFFFF;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }
        .block {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3mm;
        }
        .qr-chip {
            display: inline-block;
            padding: 2mm;
            border: 0.45mm solid #C9971C;
            background-color: rgba(255, 255, 255, 0.85);
        }
        .qr-chip img {
            display: block;
            width: 16mm;
            height: 16mm;
        }
        .verify-label {
            margin-top: 1.5mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 5.6pt;
            color: #006838;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .verify-url {
            margin-top: 0.8mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 5.2pt;
            color: #6C756E;
        }
        .footer {
            position: fixed;
            bottom: 11mm;
            left: 28mm;
            right: 28mm;
            border-top: 0.25mm solid #E4E2DC;
            padding-top: 1.6mm;
            text-align: center;
            z-index: 3;
        }
        .footer-line {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 5.2pt;
            color: #6C756E;
            line-height: 1.35;
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
                        <img src="{{ $logoBase64 }}" alt="SAPRF" style="width: 72mm;">
                    </td>
                </tr>
            </table>

            <table class="block" style="margin-top: 2.5mm;">
                <tr>
                    <td align="center">
                        <table style="width: 64mm; border-collapse: collapse;">
                            <tr>
                                <td style="width: 28mm; border-bottom: 0.3mm solid #C9971C;"></td>
                                <td style="width: 8mm; text-align: center;">
                                    <div style="width: 2.4mm; height: 2.4mm; background: #C9971C; margin: 0 auto;"></div>
                                </td>
                                <td style="width: 28mm; border-bottom: 0.3mm solid #C9971C;"></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <div class="title">Membership Certificate</div>
            <div class="subtitle">Official Membership Documentation</div>

            <table class="block">
                <tr>
                    <td align="center">
                        <table class="card" style="width: 118mm; border-collapse: separate; border-spacing: 0;">
                            <tr>
                                <td style="padding: 4mm 6mm; text-align: center;">
                                    <div class="eyebrow">This is to certify that</div>
                                    <div class="member-name">{{ $user->name }}</div>
                                    <div class="status-chip {{ $chipMuted ? 'is-muted' : '' }}">{{ $statusLabel }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <table class="block">
                <tr>
                    <td align="center">
                        <table class="card" style="width: 110mm; border-collapse: separate; border-spacing: 0;">
                            <tr>
                                <td style="padding: 1.3mm 4mm; text-align: center; background: #006838;">
                                    <span class="record-header-text">Member Record</span>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 2mm 4.5mm 2.2mm;">
                                    <table style="width: 100%; table-layout: fixed; border-collapse: collapse;">
                                        @foreach ($recordRows as $row)
                                            <tr>
                                                <td style="width: 32mm; white-space: nowrap; text-align: left;
                                                           font: 400 6.5pt 'IBM Plex Mono', DejaVu Sans Mono, monospace; color: #6C756E;
                                                           text-transform: uppercase; letter-spacing: .12em;
                                                           padding: 1.5mm 2mm 1.5mm 0; vertical-align: bottom;">{{ $row['label'] }}</td>
                                                <td style="border-bottom: 0.3mm dotted #9aa39d; vertical-align: bottom;"></td>
                                                <td style="width: 34mm; white-space: nowrap; text-align: right;
                                                           font: 600 8pt 'IBM Plex Mono', DejaVu Sans Mono, monospace; color: #171B17;
                                                           padding: 1.5mm 0 1.5mm 2mm; vertical-align: bottom;">{{ $row['value'] }}</td>
                                            </tr>
                                        @endforeach
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
            <table class="block" style="margin-top: 3.5mm;">
                <tr>
                    <td align="center">
                        <table class="card" style="width: 84mm; border-collapse: separate; border-spacing: 0;">
                            <tr>
                                <td style="padding: 2.5mm; text-align: center;">
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
            <div class="footer-line">Generated {{ $generatedDate }} · {{ $generatedTime }} SAST · build {{ $certBuild }}</div>
        </div>
    </div>
</body>
</html>
