<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $match->name }} — Squad sheet</title>
    <style>
        @page { margin: 28px 32px 36px; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1c1917; font-size: 9.5pt; margin: 0; }
        .banner { background: #14532d; color: #ecfdf5; padding: 16px 18px 14px; }
        .brand { font-size: 8pt; letter-spacing: 0.22em; text-transform: uppercase; color: #a7f3d0; margin: 0 0 4px; }
        h1 { font-size: 16pt; line-height: 1.2; margin: 0; color: #ffffff; }
        .meta { margin: 6px 0 0; font-size: 9pt; color: #d1fae5; }
        .note { margin: 10px 0 14px; font-size: 8pt; color: #57534e; }
        .grid { width: 100%; border-collapse: separate; border-spacing: 10px 10px; }
        .cell { width: 50%; vertical-align: top; }
        .squad { width: 100%; border-collapse: collapse; border: 1px solid #d6d3d1; }
        .head { background: #ecfdf5; color: #14532d; font-size: 9pt; font-weight: bold; letter-spacing: 0.06em; text-transform: uppercase; padding: 7px 8px; border-bottom: 2px solid #14532d; }
        .count { font-weight: normal; letter-spacing: 0; text-transform: none; color: #57534e; }
        .idx { width: 22px; color: #a8a29e; font-size: 8pt; text-align: right; padding: 4px 6px 4px 8px; border-bottom: 1px solid #f5f5f4; }
        .name { font-weight: bold; padding: 4px 6px; border-bottom: 1px solid #f5f5f4; }
        .meta-cell { width: 38%; text-align: right; color: #57534e; font-size: 8pt; padding: 4px 8px 4px 6px; border-bottom: 1px solid #f5f5f4; }
        .empty { padding: 8px; color: #a8a29e; font-size: 8pt; }
        .footer { margin-top: 8px; font-size: 7.5pt; color: #a8a29e; }
    </style>
</head>
<body>
    <div class="banner">
        <p class="brand">SAPRF · Squad sheet</p>
        <h1>{{ $match->name }}</h1>
        <p class="meta">
            @if ($match->match_date)
                {{ $match->match_date->format('j F Y') }}@if($match->isMultiDay() && $match->match_end_date) – {{ $match->match_end_date->format('j F Y') }}@endif
            @endif
            @if ($match->venue_name) · {{ $match->venue_name }}@endif
            @if ($match->location_display) · {{ $match->location_display }}@endif
            @if ($savedAt) · Draft {{ \Illuminate\Support\Carbon::parse($savedAt)->timezone('Africa/Johannesburg')->format('j M Y, H:i') }}@endif
        </p>
    </div>
    <p class="note">For shooters. Names, squads, ranks, and divisions only.</p>
    <table class="grid">
        @foreach (array_chunk($squads, 2) as $row)
            <tr>
                @foreach ($row as $squad)
                    <td class="cell">
                        <table class="squad">
                            <tr>
                                <td class="head" colspan="3">
                                    Squad {{ $squad['number'] }}
                                    <span class="count">· {{ count($squad['members']) }} {{ count($squad['members']) === 1 ? 'shooter' : 'shooters' }}</span>
                                </td>
                            </tr>
                            @forelse ($squad['members'] as $member)
                                <tr>
                                    <td class="idx">{{ $loop->iteration }}</td>
                                    <td class="name">{{ $member['name'] }}</td>
                                    <td class="meta-cell">
                                        {{ $member['division'] !== '' ? $member['division'] : '—' }}
                                        @if ($member['rank'] !== null)
                                            · {{ $member['rank'] }}
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="empty" colspan="3">No shooters in this squad.</td>
                                </tr>
                            @endforelse
                        </table>
                    </td>
                @endforeach
                @if (count($row) === 1)
                    <td class="cell"></td>
                @endif
            </tr>
        @endforeach
    </table>
    <p class="footer">South African Precision Rifle Federation · saprf.co.za · Phone numbers and email addresses are not on this sheet.</p>
</body>
</html>
