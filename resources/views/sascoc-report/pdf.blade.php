<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SASCOC Members Report - {{ $year }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1c1917;
            margin: 30px;
            line-height: 1.4;
        }
        h1 {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 4px;
            color: #047857;
        }
        .subtitle {
            font-size: 12px;
            color: #57534e;
            margin: 0 0 2px;
        }
        .meta {
            font-size: 10px;
            color: #78716c;
            margin-bottom: 20px;
        }
        .badge {
            display: inline-block;
            font-size: 9px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-active {
            background-color: #d1fae5;
            color: #047857;
        }
        .badge-expired {
            background-color: #fef3c7;
            color: #92400e;
        }
        .badge-lapsed {
            background-color: #fee2e2;
            color: #991b1b;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        thead th {
            background-color: #047857;
            color: #ffffff;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 5px;
            text-align: left;
            border-bottom: 2px solid #065f46;
        }
        tbody tr:nth-child(even) {
            background-color: #f5f5f4;
        }
        tbody td {
            padding: 5px;
            border-bottom: 1px solid #e7e5e4;
            font-size: 9px;
        }
        .row-expired td {
            color: #78716c;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #a8a29e;
            border-top: 1px solid #e7e5e4;
            padding-top: 10px;
        }
        .summary {
            font-size: 10px;
            color: #57534e;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <h1>SASCOC Members Report &mdash; {{ $year }}</h1>
    <p class="subtitle">South African Precision Rifle Federation (NPC)</p>
    <p class="meta">Generated: {{ now()->format('d F Y \a\t H:i') }}</p>

    <p class="summary">
        Total: <strong>{{ $members->count() }}</strong> members
        &bull; Active: <strong>{{ $members->where('status', 'active')->count() }}</strong>
        @if($includeExpired)
            &bull; Expired/Lapsed: <strong>{{ $members->whereIn('status', ['expired', 'lapsed'])->count() }}</strong>
        @endif
        &bull; All members listed have paid their membership fee.
    </p>

    <table>
        <thead>
            <tr>
                <th>SAPRF No.</th>
                <th>Full Name</th>
                <th>SA ID Number</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Province</th>
                <th>Status</th>
                <th>Start Date</th>
                <th>Expiry Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($members as $member)
                <tr class="{{ in_array($member->status, ['expired', 'lapsed']) ? 'row-expired' : '' }}">
                    <td>{{ $member->saprf_number ?? '—' }}</td>
                    <td>{{ $member->user->name ?? '—' }}</td>
                    <td>{{ $member->user->sa_id_number ?? '—' }}</td>
                    <td>{{ $member->user->email ?? '—' }}</td>
                    <td>{{ $member->user->phone ?? '—' }}</td>
                    <td>{{ $member->user->province?->name ?? '—' }}</td>
                    <td>
                        @switch($member->status)
                            @case('active')
                                <span class="badge badge-active">Active</span>
                                @break
                            @case('expired')
                                <span class="badge badge-expired">Expired</span>
                                @break
                            @case('lapsed')
                                <span class="badge badge-lapsed">Lapsed</span>
                                @break
                            @default
                                {{ ucfirst($member->status) }}
                        @endswitch
                    </td>
                    <td>{{ $member->start_date?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $member->expiry_date?->format('d/m/Y') ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" style="text-align: center; padding: 20px; color: #a8a29e;">No qualifying members found for {{ $year }}.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Generated by SAPRF Platform &bull; saprf.co.za
    </div>
</body>
</html>
