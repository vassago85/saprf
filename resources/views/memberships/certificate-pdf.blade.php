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
        .frame {
            position: fixed;
            top: 0;
            left: 0;
            width: 210mm;
            height: 297mm;
        }
        /* Full-page layout table: vertically centres the whole composition. */
        .layout {
            width: 210mm;
            height: 297mm;
            border-collapse: collapse;
        }
        .layout .cell {
            vertical-align: middle;
            padding: 20mm 20mm 24mm;
        }
        .center {
            width: 100%;
            border-collapse: collapse;
        }
        .center td {
            text-align: center;
            vertical-align: top;
        }
        .title {
            font-family: 'Saira Condensed', DejaVu Sans, sans-serif;
            font-weight: 700;
            font-size: 23pt;
            color: #006838;
            letter-spacing: 0.05em;
            text-align: center;
            text-transform: uppercase;
            line-height: 1.05;
        }
        .subtitle {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6pt;
            color: #A57B12;
            letter-spacing: 0.2em;
            text-align: center;
            text-transform: uppercase;
            margin-top: 1.5mm;
        }
        .card {
            border: 1px solid #E4E2DC;
            border-radius: 2.5mm;
            background-color: rgba(255, 255, 255, 0.50);
            margin-left: auto;
            margin-right: auto;
        }
        .divider {
            border-collapse: collapse;
            margin-left: auto;
            margin-right: auto;
        }
        .eyebrow {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
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
            margin-top: 2.5mm;
            white-space: nowrap;
            line-height: 1.1;
        }
        .connector {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.6pt;
            color: #6C756E;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            margin-top: 2.5mm;
        }
        .status-chip {
            display: inline-block;
            margin-top: 2mm;
            padding: 1.2mm 4mm;
            border: 0.4mm solid #C9971C;
            border-radius: 8mm;
            background-color: rgba(251, 246, 234, 0.75);
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6.6pt;
            color: #006838;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .status-chip.is-muted {
            border-color: #6C756E;
            color: #6C756E;
            background-color: rgba(255, 255, 255, 0.75);
        }
        .record-header-text {
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6pt;
            color: #FFFFFF;
            letter-spacing: 0.2em;
            text-transform: uppercase;
        }
        .qr-chip {
            display: inline-block;
            padding: 2.5mm;
            border: 0.45mm solid #C9971C;
            background-color: rgba(255, 255, 255, 0.85);
        }
        .qr-chip img {
            display: block;
            width: 22mm;
            height: 22mm;
        }
        .verify-label {
            margin-top: 2mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 6pt;
            color: #006838;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .verify-url {
            margin-top: 1mm;
            font-family: 'IBM Plex Mono', DejaVu Sans Mono, monospace;
            font-size: 5.6pt;
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
    <img class="frame" src="{{ $frameBase64 }}" alt="">

    <table class="layout">
        <tr>
            <td class="cell">
                {{-- Logo --}}
                <table class="center">
                    <tr>
                        <td align="center"><img src="{{ $logoBase64 }}" alt="SAPRF" style="width: 84mm;"></td>
                    </tr>
                </table>

                {{-- Divider --}}
                <table class="center" style="margin-top: 4mm;">
                    <tr>
                        <td align="center">
                            <table class="divider" style="width: 70mm;">
                                <tr>
                                    <td style="width: 31mm; border-bottom: 0.3mm solid #C9971C;"></td>
                                    <td style="width: 8mm; text-align: center;">
                                        <div style="width: 2.6mm; height: 2.6mm; background: #C9971C; margin: 0 auto;"></div>
                                    </td>
                                    <td style="width: 31mm; border-bottom: 0.3mm solid #C9971C;"></td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                {{-- Title --}}
                <table class="center" style="margin-top: 5mm;">
                    <tr>
                        <td align="center">
                            <div class="title">Membership Certificate</div>
                            <div class="subtitle">Official Membership Documentation</div>
                        </td>
                    </tr>
                </table>

                {{-- Certify card --}}
                <table class="center" style="margin-top: 13mm;">
                    <tr>
                        <td align="center">
                            <table class="card" style="width: 120mm; border-collapse: separate; border-spacing: 0;">
                                <tr>
                                    <td style="padding: 5mm 6mm; text-align: center;">
                                        <div class="eyebrow">This is to certify that</div>
                                        <div class="member-name">{{ $user->name }}</div>
                                        <div class="connector">{{ $statusArticle }}</div>
                                        <div class="status-chip {{ $chipMuted ? 'is-muted' : '' }}">{{ $statusLabel }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                {{-- Member record card --}}
                <table class="center" style="margin-top: 7mm;">
                    <tr>
                        <td align="center">
                            <table class="card" style="width: 120mm; border-collapse: separate; border-spacing: 0;">
                                <tr>
                                    <td style="padding: 1.6mm 4mm; text-align: center; background: #006838; border-radius: 2.4mm 2.4mm 0 0;">
                                        <span class="record-header-text">Member Record</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 3mm 6mm 3.2mm;">
                                        <table style="width: 100%; table-layout: fixed; border-collapse: collapse;">
                                            @foreach ($recordRows as $row)
                                                <tr>
                                                    <td style="width: 34mm; white-space: nowrap; text-align: left;
                                                               font: 400 7pt 'IBM Plex Mono', DejaVu Sans Mono, monospace; color: #6C756E;
                                                               text-transform: uppercase; letter-spacing: .13em;
                                                               padding: 1.8mm 2.5mm 1.8mm 0; vertical-align: bottom;">{{ $row['label'] }}</td>
                                                    <td style="border-bottom: 0.3mm dotted #9aa39d; vertical-align: bottom;"></td>
                                                    <td style="width: 38mm; white-space: nowrap; text-align: right;
                                                               font: 600 9pt 'IBM Plex Mono', DejaVu Sans Mono, monospace; color: #171B17;
                                                               padding: 1.8mm 0 1.8mm 2.5mm; vertical-align: bottom;">{{ $row['value'] }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                {{-- Verification card --}}
                <table class="center" style="margin-top: 8mm;">
                    <tr>
                        <td align="center">
                            <table class="card" style="width: 88mm; border-collapse: separate; border-spacing: 0;">
                                <tr>
                                    <td style="padding: 4mm; text-align: center;">
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
            </td>
        </tr>
    </table>

    <div class="footer">
        <div class="footer-line">This certificate is electronically generated and verifiable via the QR code.</div>
        <div class="footer-line">Generated {{ $generatedDate }} · {{ $generatedTime }} SAST · build {{ $certBuild }}</div>
    </div>
</body>
</html>
