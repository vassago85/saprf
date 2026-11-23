<x-layouts.app :title="'Post-match survey · '.$match->name">
    @php
        $venueLabel = $match->venue_name ?: ($match->city ?: 'the venue');
        $errors = $errors ?? session('errors');

        // 13 rating rows in the WhatsApp-approved order. Keeping the numbering
        // on the labels so the on-screen form reads the same as the briefing
        // announcement the shooters got on the group.
        $ratings = [
            ['n' => 1,  'name' => 'comms_before',   'label' => 'Pre-match communication — match schedule, squadding, start times, arrangements and general communication.'],
            ['n' => 2,  'name' => 'zero_range',     'label' => 'Zeroing and dope facilities — availability, setup and convenience of the zeroing and dope areas.'],
            ['n' => 3,  'name' => 'matchbook',      'label' => 'Matchbook quality and accuracy — layout, information, stage descriptions, distances and correctness.'],
            ['n' => 4,  'name' => 'briefings',      'label' => 'Match and stage briefings — were the briefings clear, precise and easy to understand?'],
            ['n' => 5,  'name' => 'stage_design',   'label' => 'Stage design — creativity, shooting positions, flow and overall stage layouts.'],
            ['n' => 6,  'name' => 'target_sizes',   'label' => 'Target sizes — were the target sizes appropriate and fair for the distances?'],
            ['n' => 7,  'name' => 'difficulty',     'label' => 'Overall match difficulty — was the difficulty appropriate for the level of competition?'],
            ['n' => 8,  'name' => 'range_officers', 'label' => 'Range officers and scorers — professionalism, knowledge, consistency and efficiency.'],
            ['n' => 9,  'name' => 'timekeeping',    'label' => 'Timekeeping and match efficiency — stage rotations, delays, squad management and overall match flow.'],
            ['n' => 10, 'name' => 'catering',       'label' => 'Food and catering — quality, quantity and availability.'],
            ['n' => 11, 'name' => 'toilets',        'label' => 'Toilet facilities — cleanliness, availability and general condition.'],
            ['n' => 12, 'name' => 'prize_giving',   'label' => 'Prizegiving — organisation, timing and overall presentation.'],
            ['n' => 13, 'name' => 'trophies',       'label' => 'Trophies — design, quality and presentation.'],
        ];

        $maxComment = \App\Services\MatchSurveyService::maxCommentLength();
    @endphp

    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-10">
        <div class="mb-8 border-l-4 border-emerald-600 pl-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">SAPRF match · shooter feedback scorecard</p>
            <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900 sm:text-4xl">{{ $match->name }}</h1>
            <p class="mt-2 text-sm text-stone-600">
                {{ $match->formatted_date }}
                @if($venueLabel) &middot; {{ $venueLabel }} @endif
            </p>
            <p class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <strong>Your reply is anonymous.</strong>
                SAPRF can see <em>that</em> you responded, and cannot see which answers are yours. Please answer once.
            </p>
            @if($closesAt)
                <p class="mt-2 text-xs text-stone-500">
                    Survey closes {{ $closesAt->timezone(config('app.timezone'))->format('D, j M Y') }}.
                </p>
            @endif
        </div>

        <div class="mb-6 rounded-xl border border-stone-200 bg-white p-5 text-sm text-stone-700 shadow-sm">
            <p>Thanks for joining us at the recent match. We would appreciate it if you could take a few minutes to rate the different aspects of the match. The idea is to get honest feedback, identify where we got things right and, more importantly, where we can improve for future matches.</p>
            <p class="mt-3">Please rate each category from <strong>1&ndash;10</strong>, where
                <span class="font-semibold">1 = Terrible</span>,
                <span class="font-semibold">5 = Average</span>,
                <span class="font-semibold">10 = Excellent / Perfect</span>.
            </p>
        </div>

        @if(session('error'))
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                {{ session('error') }}
            </div>
        @endif

        @if($errors && $errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                <p class="font-semibold">Please answer every rating before submitting:</p>
                <ul class="mt-1 list-inside list-disc">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('matches.survey.store', $match) }}" class="space-y-6">
            @csrf

            <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-bold text-stone-900">Rate the match</h2>
                <p class="mt-1 text-xs text-stone-500">Thirteen categories, each 1&ndash;10.</p>

                <div class="mt-5 space-y-6">
                    @foreach($ratings as $row)
                        <x-match-survey.rating-row
                            :name="$row['name']"
                            :label="$row['label']"
                            :number="$row['n']"
                            :value="old($row['name'])"
                        />
                    @endforeach
                </div>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-lg font-bold text-stone-900">A few additional questions</h2>

                <div class="mt-5 space-y-6">
                    <div>
                        <label for="highlight" class="block text-sm font-medium text-stone-900">
                            <span class="mr-1 text-stone-500 tabular-nums">14.</span>
                            What was your favourite aspect of the match?
                        </label>
                        <textarea
                            name="highlight"
                            id="highlight"
                            rows="3"
                            maxlength="{{ $maxComment }}"
                            required
                            class="mt-2 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            placeholder="What stood out?"
                        >{{ old('highlight') }}</textarea>
                    </div>

                    <div>
                        <label for="improvement" class="block text-sm font-medium text-stone-900">
                            <span class="mr-1 text-stone-500 tabular-nums">15.</span>
                            What is the ONE thing you think we should improve for the next match?
                        </label>
                        <textarea
                            name="improvement"
                            id="improvement"
                            rows="3"
                            maxlength="{{ $maxComment }}"
                            required
                            class="mt-2 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            placeholder="One concrete change"
                        >{{ old('improvement') }}</textarea>
                    </div>

                    <div>
                        <label for="other_comments" class="block text-sm font-medium text-stone-900">
                            <span class="mr-1 text-stone-500 tabular-nums">16.</span>
                            Any other comments, suggestions or constructive criticism?
                            <span class="ml-1 text-xs font-normal text-stone-400">Optional</span>
                        </label>
                        <textarea
                            name="other_comments"
                            id="other_comments"
                            rows="3"
                            maxlength="{{ $maxComment }}"
                            class="mt-2 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                            placeholder="Anything else we should know"
                        >{{ old('other_comments') }}</textarea>
                    </div>
                </div>
            </section>

            <div class="rounded-xl border border-stone-200 bg-stone-50 p-5 text-sm text-stone-600">
                Thanks again for supporting the match and being part of the PRS community. Honest feedback helps us raise the standard of our sport and future events.
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                <p class="text-xs text-stone-500">
                    Submitting once records your reply anonymously. You can only submit once.
                </p>
                <button type="submit"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                    Submit feedback
                </button>
            </div>
        </form>
    </div>
</x-layouts.app>
