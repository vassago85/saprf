<x-layouts.app :title="'Post-match survey · '.$match->name">
    @include('matches.workspace._chrome')

    @php
        $totalResponded = $aggregates['responded'];
        $totalEligible = $aggregates['eligible'];
        $rate = $aggregates['response_rate'];

        // Short labels for the aggregate dashboard — the full question text
        // lives on the form. Numbering matches the WhatsApp-approved
        // scorecard so the chart ordering lines up with what shooters saw.
        $ratingLabels = [
            'comms_before'   => '1. Pre-match communication',
            'zero_range'     => '2. Zeroing and dope facilities',
            'matchbook'      => '3. Matchbook quality and accuracy',
            'briefings'      => '4. Match and stage briefings',
            'stage_design'   => '5. Stage design',
            'target_sizes'   => '6. Target sizes',
            'difficulty'     => '7. Overall match difficulty',
            'range_officers' => '8. Range officers and scorers',
            'timekeeping'    => '9. Timekeeping and match efficiency',
            'catering'       => '10. Food and catering',
            'toilets'        => '11. Toilet facilities',
            'prize_giving'   => '12. Prizegiving',
            'trophies'       => '13. Trophies',
        ];

        $commentLabels = [
            'highlight'      => ['title' => '14. Favourite aspect', 'tone' => 'emerald'],
            'improvement'    => ['title' => '15. One thing to improve', 'tone' => 'amber'],
            'other_comments' => ['title' => '16. Other comments, suggestions, criticism', 'tone' => 'stone'],
        ];

        $toneClasses = [
            'emerald' => 'border-emerald-100 bg-emerald-50 text-emerald-900',
            'amber'   => 'border-amber-100 bg-amber-50 text-amber-900',
            'stone'   => 'border-stone-100 bg-stone-50 text-stone-800',
        ];

        $ratingMin = \App\Services\MatchSurveyService::RATING_MIN;
        $ratingMax = \App\Services\MatchSurveyService::RATING_MAX;
    @endphp

    <div class="mt-6 space-y-6">
        {{-- Header + anonymity reminder --}}
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Shooter feedback scorecard</p>
                    <h1 class="mt-1 font-heading text-2xl font-bold text-emerald-950">{{ $match->name }}</h1>
                    <p class="mt-1 max-w-2xl text-xs text-emerald-900">
                        Answers are stored anonymously. SAPRF sees <em>that</em> each shooter replied but not what they said. Match directors do not see this page.
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-emerald-700">
                        {{ $totalResponded }} of {{ $totalEligible }} shooters replied
                    </p>
                    @if($rate !== null)
                        <p class="mt-0.5 font-heading text-3xl font-bold text-emerald-950 tabular-nums">{{ $rate }}%</p>
                    @endif
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                @if($match->survey_invites_sent_at !== null)
                    <button type="button" disabled
                            class="inline-flex items-center gap-1.5 rounded-md border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-sm font-semibold text-emerald-800 opacity-70">
                        Invite emailed {{ $match->survey_invites_sent_at->timezone(config('app.timezone'))->format('j M Y, H:i') }}
                    </button>
                @else
                    <form method="POST" action="{{ route('matches.survey.invite', $match) }}">
                        @csrf
                        <button type="submit"
                                onclick="return confirm('Email every eligible shooter a link to the anonymous survey? This can only be done once.');"
                                class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">
                            Email shooters a survey link
                        </button>
                    </form>
                @endif

                @if($windowIsOpen && $closesAt)
                    <span class="text-xs text-emerald-800">
                        Closes {{ $closesAt->timezone(config('app.timezone'))->format('j M Y') }}
                    </span>
                @else
                    <span class="text-xs text-stone-500">Window closed</span>
                @endif
            </div>
        </div>

        @if(session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                {{ session('error') }}
            </div>
        @endif

        @if($totalResponded === 0)
            <div class="rounded-2xl border border-stone-200 bg-white p-8 text-center shadow-sm">
                <p class="text-sm text-stone-600">
                    No replies yet. Email the invite to eligible shooters to kick things off.
                </p>
            </div>
        @else
            {{-- Ratings grid --}}
            <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-bold text-stone-900">Ratings</h2>
                <p class="mt-1 text-xs text-stone-500">Scale: 1 = Terrible &middot; 5 = Average &middot; 10 = Excellent. Averages are across shooters who gave a rating.</p>

                <div class="mt-5 grid grid-cols-1 gap-x-8 gap-y-6 sm:grid-cols-2">
                    @foreach($aggregates['ratings'] as $key => $row)
                        <div>
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-sm font-medium text-stone-900">{{ $ratingLabels[$key] ?? $key }}</p>
                                @if($row['count'] > 0)
                                    <p class="font-heading text-lg font-bold text-stone-900 tabular-nums">
                                        {{ number_format($row['average'], 2) }}
                                        <span class="text-xs font-normal text-stone-400">/ {{ $ratingMax }}</span>
                                    </p>
                                @else
                                    <p class="text-xs text-stone-400">No ratings</p>
                                @endif
                            </div>
                            @if($row['count'] > 0)
                                <div class="mt-2 flex items-end gap-1">
                                    @for($i = $ratingMin; $i <= $ratingMax; $i++)
                                        @php
                                            $count = $row['histogram'][$i] ?? 0;
                                            $max = max($row['histogram']);
                                            $heightPct = $max > 0 ? max(4, (int) round(($count / $max) * 100)) : 4;
                                        @endphp
                                        <div class="flex flex-1 flex-col items-center">
                                            <div class="w-full rounded-t bg-emerald-500/80"
                                                 style="height: {{ $heightPct }}%; min-height: 2px;"
                                                 title="{{ $count }} rated {{ $i }}">
                                            </div>
                                            <span class="mt-1 text-[10px] font-mono text-stone-500 tabular-nums">{{ $i }}</span>
                                            <span class="text-[10px] text-stone-400 tabular-nums">{{ $count }}</span>
                                        </div>
                                    @endfor
                                </div>
                            @endif
                            <p class="mt-1 text-[11px] text-stone-400">
                                {{ $row['count'] }} {{ \Illuminate\Support\Str::plural('rating', $row['count']) }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Three comment panels (shuffled in the service so reading order
                 does not match submission order). --}}
            <section class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                @foreach($commentLabels as $key => $meta)
                    @php
                        $list = $aggregates['comments'][$key] ?? [];
                        $itemTone = $toneClasses[$meta['tone']] ?? $toneClasses['stone'];
                    @endphp
                    <div class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                        <h2 class="font-heading text-lg font-bold text-stone-900">{{ $meta['title'] }}</h2>
                        <p class="mt-1 text-xs text-stone-500">{{ count($list) }} {{ \Illuminate\Support\Str::plural('comment', count($list)) }}</p>
                        @if($list === [])
                            <p class="mt-4 text-sm text-stone-500 italic">No comments yet.</p>
                        @else
                            <ul class="mt-4 space-y-3">
                                @foreach($list as $comment)
                                    <li class="rounded-lg border {{ $itemTone }} p-3 text-sm">
                                        &ldquo;{{ $comment }}&rdquo;
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif
    </div>
</x-layouts.app>
