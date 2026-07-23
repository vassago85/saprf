<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAPRF Membership Certificate — {{ $membership->saprf_number }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=Saira+Condensed:wght@600;700&family=Saira:wght@600&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #006838;
            --gold: #C9971C;
            --gold-deep: #A57B12;
            --ink: #171B17;
            --slate: #6C756E;
            --line: #E4E2DC;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        @page {
            size: A4;
            margin: 0;
        }

        html, body {
            width: 210mm;
            height: 297mm;
            background: #FFFFFF;
        }

        body {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .page {
            position: relative;
            width: 210mm;
            height: 297mm;
            background: #FFFFFF;
            overflow: hidden;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color: var(--ink);
        }

        .frame {
            position: absolute;
            inset: 0;
            width: 210mm;
            height: 297mm;
            pointer-events: none;
            z-index: 1;
        }

        .content {
            position: relative;
            z-index: 2;
            height: 100%;
            padding: 24mm 16mm 14mm;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .logo {
            width: 102mm;
            height: auto;
            display: block;
            margin: 0 auto;
        }

        .ornament {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3.2mm;
            margin-top: 6.5mm;
            margin-bottom: 5mm;
        }

        .ornament-rule {
            width: 26mm;
            height: 0.35mm;
            background: var(--gold);
        }

        .ornament-diamond {
            width: 2.2mm;
            height: 2.2mm;
            background: var(--gold);
            transform: rotate(45deg);
            flex-shrink: 0;
        }

        .title {
            font-family: 'Saira Condensed', sans-serif;
            font-weight: 700;
            font-size: 31pt;
            line-height: 1;
            color: var(--green);
            letter-spacing: .06em;
            text-align: center;
            text-transform: uppercase;
        }

        .subtitle {
            margin-top: 2.8mm;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 400;
            font-size: 6.6pt;
            color: var(--gold-deep);
            letter-spacing: .38em;
            text-align: center;
            text-transform: uppercase;
        }

        .card {
            border: 1px solid var(--line);
            border-radius: 2mm;
            background: #FFFFFF;
            width: 100%;
            max-width: 168mm;
        }

        .card + .card {
            margin-top: 7.5mm;
        }

        .card-certify {
            margin-top: 7.5mm;
            padding: 6mm 8mm 5.5mm;
            text-align: center;
        }

        .eyebrow {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 7pt;
            font-weight: 400;
            color: var(--slate);
            letter-spacing: .32em;
            text-transform: uppercase;
        }

        .member-name {
            margin-top: 3.5mm;
            font-family: 'Saira', sans-serif;
            font-weight: 600;
            font-size: {{ $memberNameSize }};
            line-height: 1.1;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: clip;
            max-width: 100%;
        }

        .status-chip {
            display: inline-block;
            margin-top: 4mm;
            padding: 1.6mm 4.5mm;
            border-radius: 999px;
            border: 0.45mm solid var(--gold);
            background: rgba(201, 151, 28, .06);
            font-family: 'IBM Plex Mono', monospace;
            font-size: 7pt;
            font-weight: 400;
            color: var(--green);
            letter-spacing: .3em;
            text-transform: uppercase;
        }

        .status-chip.is-muted {
            border-color: var(--slate);
            color: var(--slate);
            background: transparent;
        }

        .card-record {
            width: 110mm;
            max-width: 110mm;
            overflow: hidden;
            padding: 0 0 2mm;
        }

        .record-header {
            height: 4mm;
            background: var(--green);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .record-header span {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 6.2pt;
            font-weight: 400;
            color: #FFFFFF;
            letter-spacing: .32em;
            text-transform: uppercase;
        }

        .record-body {
            padding: 3.5mm 5mm 3mm;
        }

        .record-row {
            display: flex;
            align-items: baseline;
            min-height: 7.2mm;
            margin-bottom: 1.2mm;
        }

        .record-label {
            flex: 0 0 34mm;
            width: 34mm;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 7pt;
            font-weight: 400;
            color: var(--slate);
            letter-spacing: .28em;
            text-transform: uppercase;
        }

        .record-leader {
            flex: 1 1 auto;
            margin: 0 2.5mm;
            border-bottom: 0.3mm dotted rgba(108, 117, 110, .55);
            min-width: 8mm;
            position: relative;
            top: -1.2mm;
        }

        .record-value {
            flex: 0 0 auto;
            max-width: 48mm;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 9pt;
            font-weight: 600;
            color: var(--ink);
            text-align: right;
            white-space: nowrap;
        }

        .card-verify {
            width: 110mm;
            max-width: 110mm;
            padding: 5.5mm 6mm 5mm;
            text-align: center;
        }

        .qr-chip {
            position: relative;
            width: 30mm;
            height: 30mm;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #FFFFFF;
        }

        .qr-chip img {
            width: 24mm;
            height: 24mm;
            display: block;
        }

        .qr-corner {
            position: absolute;
            width: 4.5mm;
            height: 4.5mm;
            border-color: var(--gold);
            border-style: solid;
            border-width: 0;
        }

        .qr-corner--tl { top: 0; left: 0; border-top-width: 0.5mm; border-left-width: 0.5mm; }
        .qr-corner--tr { top: 0; right: 0; border-top-width: 0.5mm; border-right-width: 0.5mm; }
        .qr-corner--bl { bottom: 0; left: 0; border-bottom-width: 0.5mm; border-left-width: 0.5mm; }
        .qr-corner--br { bottom: 0; right: 0; border-bottom-width: 0.5mm; border-right-width: 0.5mm; }

        .verify-label {
            margin-top: 3.2mm;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 6.2pt;
            font-weight: 400;
            color: var(--green);
            letter-spacing: .3em;
            text-transform: uppercase;
        }

        .verify-url {
            margin-top: 1.6mm;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 6.4pt;
            font-weight: 400;
            color: var(--slate);
            letter-spacing: .02em;
            word-break: break-all;
        }

        .footer {
            margin-top: auto;
            width: 100%;
            max-width: 168mm;
            padding-top: 3.5mm;
            border-top: 0.25mm solid var(--line);
            text-align: center;
        }

        .footer-line {
            font-family: 'IBM Plex Mono', monospace;
            font-size: 5.8pt;
            font-weight: 400;
            color: var(--slate);
            letter-spacing: .04em;
            line-height: 1.55;
        }
    </style>
</head>
<body>
@php
    // Mil-scale ticks on the outer gold frame (SVG user units = mm).
    $outer = ['x' => 8, 'y' => 8, 'w' => 194, 'h' => 281];
    $ticks = '';
    for ($i = 0; $i <= 194; $i += 10) {
        $len = ($i % 50 === 0) ? 2.4 : 1.6;
        $x = $outer['x'] + $i;
        $ticks .= sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s"/>', $x, $outer['y'], $x, $outer['y'] + $len);
        $ticks .= sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s"/>', $x, $outer['y'] + $outer['h'], $x, $outer['y'] + $outer['h'] - $len);
    }
    for ($i = 0; $i <= 281; $i += 10) {
        $len = ($i % 50 === 0) ? 2.4 : 1.6;
        $y = $outer['y'] + $i;
        $ticks .= sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s"/>', $outer['x'], $y, $outer['x'] + $len, $y);
        $ticks .= sprintf('<line x1="%s" y1="%s" x2="%s" y2="%s"/>', $outer['x'] + $outer['w'], $y, $outer['x'] + $outer['w'] - $len, $y);
    }

    $bracket = 9;
    $ox = $outer['x'];
    $oy = $outer['y'];
    $ow = $outer['w'];
    $oh = $outer['h'];
@endphp
    <div class="page">
        <svg class="frame" viewBox="0 0 210 297" preserveAspectRatio="none" aria-hidden="true">
            {{-- Outer gold frame --}}
            <rect x="8" y="8" width="194" height="281" fill="none" stroke="var(--gold)" stroke-width="0.55"/>

            {{-- Inner green hairline --}}
            <rect x="10.6" y="10.6" width="188.8" height="275.8" fill="none" stroke="var(--green)" stroke-width="0.35" opacity="0.5"/>

            {{-- Mil ticks --}}
            <g stroke="var(--gold)" stroke-width="0.22" opacity="0.8" fill="none">
                {!! $ticks !!}
            </g>

            {{-- Corner L-brackets --}}
            <g stroke="var(--gold)" stroke-width="0.9" fill="none" stroke-linecap="square">
                <path d="M {{ $ox }} {{ $oy + $bracket }} V {{ $oy }} H {{ $ox + $bracket }}"/>
                <path d="M {{ $ox + $ow - $bracket }} {{ $oy }} H {{ $ox + $ow }} V {{ $oy + $bracket }}"/>
                <path d="M {{ $ox }} {{ $oy + $oh - $bracket }} V {{ $oy + $oh }} H {{ $ox + $bracket }}"/>
                <path d="M {{ $ox + $ow - $bracket }} {{ $oy + $oh }} H {{ $ox + $ow }} V {{ $oy + $oh - $bracket }}"/>
            </g>

            {{-- Faint reticle watermark behind member-name area --}}
            <g transform="translate(105 118)" stroke="var(--green)" stroke-width="0.28" fill="none" opacity="0.06">
                <circle r="52"/>
                <circle r="34"/>
                {{-- Crosshair with open centre gap --}}
                <line x1="-52" y1="0" x2="-8" y2="0"/>
                <line x1="8" y1="0" x2="52" y2="0"/>
                <line x1="0" y1="-52" x2="0" y2="-8"/>
                <line x1="0" y1="8" x2="0" y2="52"/>
                {{-- Mil hashes on stadia --}}
                @foreach ([-40, -20, 20, 40] as $m)
                    <line x1="{{ $m }}" y1="-2.2" x2="{{ $m }}" y2="2.2"/>
                    <line x1="-2.2" y1="{{ $m }}" x2="2.2" y2="{{ $m }}"/>
                @endforeach
            </g>
        </svg>

        <div class="content">
            <img class="logo" src="{{ $logoBase64 }}" alt="South African Precision Rifle Federation">

            <div class="ornament" aria-hidden="true">
                <span class="ornament-rule"></span>
                <span class="ornament-diamond"></span>
                <span class="ornament-rule"></span>
            </div>

            <h1 class="title">Membership Certificate</h1>
            <p class="subtitle">Official Membership Documentation</p>

            <div class="card card-certify">
                <p class="eyebrow">This is to certify that</p>
                <p class="member-name">{{ $user->name }}</p>
                <span class="status-chip {{ $chipMuted ? 'is-muted' : '' }}">{{ $statusLabel }}</span>
            </div>

            <div class="card card-record">
                <div class="record-header"><span>Member Record</span></div>
                <div class="record-body">
                    @foreach ($recordRows as $row)
                        <div class="record-row">
                            <div class="record-label">{{ $row['label'] }}</div>
                            <div class="record-leader"></div>
                            <div class="record-value">{{ $row['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card card-verify">
                <div class="qr-chip">
                    <span class="qr-corner qr-corner--tl"></span>
                    <span class="qr-corner qr-corner--tr"></span>
                    <span class="qr-corner qr-corner--bl"></span>
                    <span class="qr-corner qr-corner--br"></span>
                    <img src="{{ $qrBase64 }}" alt="Membership verification QR code">
                </div>
                <p class="verify-label">Scan to verify membership</p>
                <p class="verify-url">{{ $verifyUrl }}</p>
            </div>

            <footer class="footer">
                <p class="footer-line">This certificate is electronically generated and verifiable via the QR code.</p>
                <p class="footer-line">Generated {{ $generatedDate }} · {{ $generatedTime }} SAST</p>
            </footer>
        </div>
    </div>
</body>
</html>
