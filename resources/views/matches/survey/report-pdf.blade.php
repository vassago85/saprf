<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Post-match survey — {{ $match->name }}</title>
    <style>
        @page { margin: 22mm 16mm 18mm 16mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #1c1917;
            line-height: 1.4;
        }
        h1 { font-size: 18pt; margin: 0 0 4pt; color: #065f46; }
        h2 { font-size: 13pt; margin: 20pt 0 8pt; color: #065f46; border-bottom: 1px solid #d6d3d1; padding-bottom: 3pt; }
        h3 { font-size: 11pt; margin: 12pt 0 4pt; color: #1c1917; }
        p { margin: 0 0 6pt; }
        .meta { font-size: 9pt; color: #57534e; margin-bottom: 14pt; }
        .anon {
            background: #ecfdf5;
            border-left: 3pt solid #10b981;
            padding: 8pt 10pt;
            margin: 10pt 0 14pt;
            font-size: 9pt;
            color: #064e3b;
        }
        .headline {
            display: inline-block;
            width: 32%;
            padding: 8pt 10pt;
            background: #f5f5f4;
            border: 1px solid #e7e5e4;
            margin-right: 0.5%;
            margin-bottom: 8pt;
            vertical-align: top;
        }
        .headline .n { font-size: 20pt; font-weight: bold; color: #0f766e; }
        .headline .l { font-size: 8pt; color: #57534e; text-transform: uppercase; letter-spacing: 0.4pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 6pt; }
        th, td { text-align: left; padding: 5pt 6pt; border-bottom: 1px solid #e7e5e4; font-size: 9pt; }
        th { background: #f5f5f4; font-weight: 600; color: #44403c; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        td.score { font-weight: bold; color: #0f766e; }
        .bar-cell { width: 180pt; }
        .bar-row { display: block; line-height: 1; }
        .bar-row .tick {
            display: inline-block;
            width: 16pt;
            text-align: center;
            margin-right: 1pt;
            font-size: 8pt;
            color: #78716c;
        }
        .bar-row .count {
            display: inline-block;
            width: 16pt;
            text-align: center;
            margin-right: 1pt;
            font-size: 8pt;
            color: #0f766e;
            font-weight: 600;
        }
        .comment {
            padding: 6pt 8pt;
            margin: 3pt 0;
            font-size: 9pt;
            background: #fafaf9;
            border-left: 2pt solid #a8a29e;
        }
        .comment-highlight { border-left-color: #10b981; background: #ecfdf5; }
        .comment-improvement { border-left-color: #f59e0b; background: #fffbeb; }
        .empty { color: #a8a29e; font-style: italic; font-size: 9pt; }
        .footer {
            position: fixed; bottom: -8mm; left: 0; right: 0;
            font-size: 8pt; color: #a8a29e; text-align: center;
        }
    </style>
</head>
<body>
    <h1>{{ $match->name }}</h1>
    <p class="meta">
        Shooter feedback scorecard
        &middot; {{ $match->formatted_date ?? optional($match->match_date)->format('j M Y') }}
        @if($match->venue_name) &middot; {{ $match->venue_name }} @endif
        &middot; Window closed {{ $closedAt?->timezone(config('app.timezone'))->format('j M Y') }}
    </p>

    <div class="anon">
        <strong>Anonymous data.</strong>
        {{ $aggregates['responded'] }} of {{ $aggregates['eligible'] }} eligible shooters replied
        @if($aggregates['response_rate'] !== null)
            ({{ $aggregates['response_rate'] }}%).
        @else
            .
        @endif
        SAPRF stored who replied separately from what they said, so this report shows aggregate numbers and shuffled comments only.
    </div>

    @if($aggregates['responded'] === 0)
        <p class="empty">No replies were received before the window closed.</p>
    @else
        {{-- Headline metrics --}}
        @php
            $overallSamples = collect($aggregates['ratings'])->filter(fn ($r) => $r['count'] > 0);
            $overallAverage = $overallSamples->count() > 0
                ? round($overallSamples->avg('average'), 2)
                : null;
            $topRated = $overallSamples->sortByDesc('average')->first();
            $topRatedKey = $overallSamples->sortByDesc('average')->keys()->first();
            $lowRated = $overallSamples->sortBy('average')->first();
            $lowRatedKey = $overallSamples->sortBy('average')->keys()->first();
        @endphp

        <div>
            <div class="headline">
                <div class="l">Overall average</div>
                <div class="n">{{ $overallAverage !== null ? number_format($overallAverage, 2) : '—' }}</div>
                <div class="l">out of {{ $ratingMax }}</div>
            </div>
            <div class="headline">
                <div class="l">Top rated</div>
                <div class="n">{{ $topRated ? number_format($topRated['average'], 2) : '—' }}</div>
                <div class="l">{{ $topRatedKey ? ($ratingLabels[$topRatedKey] ?? $topRatedKey) : '' }}</div>
            </div>
            <div class="headline">
                <div class="l">Lowest rated</div>
                <div class="n">{{ $lowRated ? number_format($lowRated['average'], 2) : '—' }}</div>
                <div class="l">{{ $lowRatedKey ? ($ratingLabels[$lowRatedKey] ?? $lowRatedKey) : '' }}</div>
            </div>
        </div>

        {{-- Ratings table --}}
        <h2>Ratings (1&ndash;{{ $ratingMax }})</h2>
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th class="num">Average</th>
                    <th class="num">Replies</th>
                    <th class="bar-cell">Distribution</th>
                </tr>
            </thead>
            <tbody>
                @foreach($aggregates['ratings'] as $key => $row)
                    <tr>
                        <td>{{ $ratingLabels[$key] ?? $key }}</td>
                        <td class="num score">
                            @if($row['count'] > 0)
                                {{ number_format($row['average'], 2) }}
                            @else
                                &mdash;
                            @endif
                        </td>
                        <td class="num">{{ $row['count'] }}</td>
                        <td class="bar-cell">
                            <div class="bar-row">
                                @for($i = $ratingMin; $i <= $ratingMax; $i++)
                                    <span class="count">{{ $row['histogram'][$i] ?? 0 }}</span>
                                @endfor
                            </div>
                            <div class="bar-row">
                                @for($i = $ratingMin; $i <= $ratingMax; $i++)
                                    <span class="tick">{{ $i }}</span>
                                @endfor
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Comments --}}
        @foreach($commentSections as $key => $meta)
            @php $list = $aggregates['comments'][$key] ?? []; @endphp
            <h2>{{ $meta['title'] }}</h2>
            @if($list === [])
                <p class="empty">No comments.</p>
            @else
                @foreach($list as $comment)
                    <div class="comment comment-{{ $meta['class'] ?? 'default' }}">&ldquo;{{ $comment }}&rdquo;</div>
                @endforeach
            @endif
        @endforeach
    @endif

    <div class="footer">
        Generated {{ now()->timezone(config('app.timezone'))->format('j M Y H:i') }} &middot; SAPRF post-match survey &middot; Anonymous aggregate report
    </div>
</body>
</html>
