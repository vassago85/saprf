<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $meeting->title }} — Minutes</title>
    <style>
        :root {
            --ink: #1c1917;
            --muted: #57534e;
            --line: #e7e5e4;
            --brand: #047857;
            --warn: #b45309;
            --danger: #b91c1c;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fafaf9; color: var(--ink); font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; font-size: 12pt; line-height: 1.45; }
        .page { max-width: 780px; margin: 0 auto; padding: 32px 40px 64px; background: white; }
        h1 { font-size: 20pt; margin: 0 0 4px; font-weight: 700; }
        h2 { font-size: 14pt; margin: 28px 0 10px; padding-bottom: 4px; border-bottom: 1px solid var(--line); font-weight: 600; }
        h3 { font-size: 12pt; margin: 16px 0 6px; font-weight: 600; }
        p { margin: 6px 0; }
        .subtitle { color: var(--muted); font-size: 10.5pt; margin: 0 0 20px; }
        .meta { font-size: 10pt; color: var(--muted); margin: 4px 0 0; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 9pt; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
        .badge-closed { background: #d1fae5; color: #065f46; }
        .badge-circulated { background: #fef3c7; color: #92400e; }
        .badge-adopted { background: #dbeafe; color: #1e40af; }
        .badge-confidential { background: #fee2e2; color: #991b1b; }
        .actionbar { display: flex; gap: 8px; justify-content: flex-end; margin-bottom: 16px; }
        .btn { display: inline-block; padding: 8px 14px; border-radius: 8px; background: var(--brand); color: white; text-decoration: none; font-size: 10pt; font-weight: 600; border: 0; cursor: pointer; }
        .btn-ghost { background: white; color: var(--ink); border: 1px solid var(--line); }
        .agenda-item { border: 1px solid var(--line); border-radius: 8px; padding: 14px 16px; margin: 10px 0; page-break-inside: avoid; }
        .agenda-item.confidential { background: #fef2f2; border-color: #fecaca; }
        .agenda-title { font-weight: 600; font-size: 12.5pt; margin: 0 0 6px; }
        .briefing, .minutes { white-space: pre-wrap; }
        .briefing { color: var(--muted); font-size: 11pt; padding: 8px 12px; border-left: 3px solid var(--line); background: #fafaf9; margin: 6px 0; }
        .minutes { padding: 4px 0; }
        .kicker { text-transform: uppercase; font-size: 9pt; letter-spacing: 0.05em; color: var(--muted); font-weight: 600; margin: 8px 0 2px; }
        .actions-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 10.5pt; }
        .actions-table th { text-align: left; padding: 6px 8px; background: #f5f5f4; font-weight: 600; font-size: 9.5pt; text-transform: uppercase; letter-spacing: 0.03em; color: var(--muted); border-bottom: 1px solid var(--line); }
        .actions-table td { padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .empty { color: var(--muted); font-style: italic; padding: 6px 0; }
        .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid var(--line); font-size: 9.5pt; color: var(--muted); }

        @media print {
            html, body { background: white; }
            .page { max-width: none; padding: 0.5in 0.7in; margin: 0; }
            .no-print { display: none !important; }
            h2, .agenda-item { page-break-inside: avoid; }
            a { color: var(--ink); text-decoration: none; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="actionbar no-print">
            <a href="{{ route('exco.meetings.show', $meeting) }}" class="btn btn-ghost">← Back to meeting</a>
            <button type="button" class="btn" onclick="window.print()">Print / Save as PDF</button>
        </div>

        <h1>{{ $meeting->title }}</h1>
        <p class="subtitle">
            Minutes of the {{ strtolower($meeting->type->label()) }} sitting held on
            {{ $meeting->scheduled_at->format('l, d F Y') }} at {{ $meeting->scheduled_at->format('H:i') }}
            @if ($meeting->location) — {{ $meeting->location }} @endif.
        </p>

        <p class="meta">
            <span class="badge badge-closed">{{ $meeting->status->label() }}</span>
            @if ($meeting->minutesAreCirculated())
                <span class="badge badge-circulated">
                    Circulated {{ $meeting->minutes_circulated_at->format('d M Y') }}
                    @if ($meeting->minutesCirculator) by {{ $meeting->minutesCirculator->name }} @endif
                </span>
            @endif
            @if ($meeting->minutesAreAdopted())
                <span class="badge badge-adopted">
                    Adopted {{ $meeting->minutes_adopted_at->format('d M Y') }}
                    @if ($meeting->adoptedAtMeeting) at {{ $meeting->adoptedAtMeeting->title }} @endif
                </span>
            @endif
        </p>

        {{-- Standing MEMBERS block. Independent of per-meeting
             attendance — this is a snapshot of who is on ExCo right
             now, matching the "MEMBERS" heading of a notice. --}}
        @if ($committee->isNotEmpty())
            <h2>Members</h2>
            <table class="actions-table">
                <thead>
                    <tr>
                        <th style="width: 45%;">Name</th>
                        <th>Position</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($committee as $member)
                        <tr>
                            <td>{{ $member->name }}</td>
                            <td>{{ $member->exco_position ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($meeting->attendance_notes)
            <h2>Attendance for this meeting</h2>
            <p class="minutes">{{ $meeting->attendance_notes }}</p>
        @endif

        <h2>Agenda and minutes</h2>
        @forelse ($meeting->agendaItems as $index => $item)
            <div class="agenda-item {{ $item->isConfidential() ? 'confidential' : '' }}">
                <p class="agenda-title">
                    {{ $index + 1 }}. {{ $item->title }}
                    @if ($item->isConfidential())
                        <span class="badge badge-confidential" style="margin-left: 6px;">Confidential</span>
                    @endif
                    @if ($item->disciplinaryCase)
                        <span class="badge badge-confidential" style="margin-left: 6px; background: #fef3c7; color: #92400e;">
                            {{ $item->disciplinaryCase->reference }}
                        </span>
                    @endif
                </p>

                @if ($item->briefing)
                    <p class="kicker">Briefing</p>
                    <p class="briefing">{{ $item->briefing }}</p>
                @endif

                @if ($item->minutes)
                    <p class="kicker">Minutes</p>
                    <p class="minutes">{{ $item->minutes }}</p>
                @else
                    <p class="empty">No minutes captured for this item.</p>
                @endif
            </div>
        @empty
            <p class="empty">No agenda items recorded.</p>
        @endforelse

        @if ($meeting->amendments->isNotEmpty())
            <h2>Amendments proposed during circulation review</h2>
            <table class="actions-table">
                <thead>
                    <tr>
                        <th>Proposed by</th>
                        <th>Affects</th>
                        <th>Proposed change</th>
                        <th>Outcome</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($meeting->amendments as $a)
                        <tr>
                            <td>
                                {{ $a->proposer?->name ?? '—' }}
                                <br><span style="color: var(--muted); font-size: 9.5pt;">{{ $a->created_at->format('d M Y') }}</span>
                            </td>
                            <td>{{ $a->agendaItem?->title ?? 'General' }}</td>
                            <td>{{ $a->proposed_text }}</td>
                            <td>
                                {{ $a->status->label() }}
                                @if ($a->resolver)
                                    <br><span style="color: var(--muted); font-size: 9.5pt;">by {{ $a->resolver->name }}</span>
                                @endif
                                @if ($a->resolution_notes)
                                    <br><span style="color: var(--muted); font-size: 9.5pt; font-style: italic;">"{{ $a->resolution_notes }}"</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($meeting->actions->isNotEmpty())
            <h2>Action items</h2>
            <table class="actions-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Owner</th>
                        <th>Due</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($meeting->actions as $action)
                        <tr>
                            <td>
                                {{ $action->title }}
                                @if ($action->agendaItem)
                                    <br><span style="color: var(--muted); font-size: 9.5pt;">Item: {{ $action->agendaItem->title }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($action->assignee)
                                    {{ $action->assignee->name }}@if ($action->assignee->exco_position) ({{ $action->assignee->exco_position }})@endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $action->due_on?->format('d M Y') ?? '—' }}</td>
                            <td>{{ $action->status->label() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <p class="footer">
            SAPRF · Confidential to National Executive Committee ·
            Prepared by {{ $meeting->creator?->name ?? '—' }} ·
            Generated {{ now()->format('d M Y H:i') }} SAST.
        </p>
    </div>
</body>
</html>
