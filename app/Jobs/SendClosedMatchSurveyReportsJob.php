<?php

namespace App\Jobs;

use App\Models\MatchEvent;
use App\Models\User;
use App\Notifications\MatchSurveyReportReadyNotification;
use App\Services\MatchSurveyService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Daily sweep that mails the closing report for every match whose
 * post-match survey window has elapsed but whose report email has not
 * yet been sent.
 *
 * Scheduled daily in routes/console.php. The `survey_report_sent_at`
 * column acts as the idempotency marker — a match is picked up by the
 * first run after the window closes, then skipped forever after. If
 * the job crashes mid-batch, the next day's run re-picks the matches
 * that never got the column stamped.
 *
 * Recipients:
 *   - Match director (matches.created_by), if they have a verified email,
 *   - Every exco / owner / admin with a verified email,
 * deduplicated so a Match Director who is also Exco doesn't get it
 * twice.
 *
 * Anonymity invariant: the attached PDF and the inline summary both
 * come from MatchSurveyService::aggregates(), which never joins the
 * respondent table to the response table. Per-shooter answers are not
 * accessible from inside this job by construction.
 */
class SendClosedMatchSurveyReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MatchSurveyService $surveys): void
    {
        $now = now();

        // Pre-filter: only completed matches of the right series level
        // with a null `survey_report_sent_at`. The window-closed check is
        // computed below because it depends on match_end_date / match_date
        // + the service constant, not a stored column.
        $candidates = MatchEvent::query()
            ->where('status', 'completed')
            ->whereIn('series_level', MatchSurveyService::APPLICABLE_SERIES_LEVELS)
            ->whereNull('survey_report_sent_at')
            ->get();

        $staff = $this->staffRecipients();

        foreach ($candidates as $match) {
            if (! $surveys->isApplicableTo($match)) {
                continue;
            }

            $closesAt = $surveys->windowClosesAt($match);
            if ($closesAt === null || $now->lt($closesAt)) {
                // Window still open — leave for a later run.
                continue;
            }

            $this->dispatchReport($match, $surveys, $staff, $closesAt);
        }
    }

    /**
     * @param  Collection<int, User>  $staff
     */
    private function dispatchReport(
        MatchEvent $match,
        MatchSurveyService $surveys,
        Collection $staff,
        \Illuminate\Support\Carbon $closedAt,
    ): void {
        $aggregates = $surveys->aggregates($match);
        $summary = $this->summariseAggregates($aggregates);

        $pdfContents = $this->renderPdf($match, $aggregates, $closedAt);

        $recipients = $this->recipientsFor($match, $staff);
        if ($recipients->isEmpty()) {
            Log::info('match-survey.report.no-recipients', [
                'match_id' => $match->id,
                'match_name' => $match->name,
            ]);
            // Still stamp the match so we don't keep rebuilding a PDF no
            // one will receive — if an MD lands in the system later they
            // can always open the results page in-app.
            $match->forceFill(['survey_report_sent_at' => now()])->save();

            return;
        }

        Notification::send(
            $recipients,
            new MatchSurveyReportReadyNotification($match, $summary, $pdfContents),
        );

        $match->forceFill(['survey_report_sent_at' => now()])->save();

        Log::info('match-survey.report.sent', [
            'match_id' => $match->id,
            'match_name' => $match->name,
            'recipients' => $recipients->pluck('id')->all(),
            'responded' => $aggregates['responded'],
            'eligible' => $aggregates['eligible'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $aggregates
     * @return array<string, mixed>
     */
    private function summariseAggregates(array $aggregates): array
    {
        $samples = collect($aggregates['ratings'])
            ->filter(fn (array $row) => $row['count'] > 0);

        $overallAverage = $samples->count() > 0
            ? round($samples->avg('average'), 2)
            : null;

        $labels = self::ratingLabels();

        $top = $samples->sortByDesc('average')->first();
        $topKey = $samples->sortByDesc('average')->keys()->first();
        $low = $samples->sortBy('average')->first();
        $lowKey = $samples->sortBy('average')->keys()->first();

        return [
            'responded' => $aggregates['responded'],
            'eligible' => $aggregates['eligible'],
            'response_rate' => $aggregates['response_rate'],
            'overall_average' => $overallAverage,
            'top_label' => $topKey ? ($labels[$topKey] ?? $topKey) : null,
            'top_score' => $top['average'] ?? null,
            'low_label' => $lowKey ? ($labels[$lowKey] ?? $lowKey) : null,
            'low_score' => $low['average'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $aggregates
     */
    private function renderPdf(MatchEvent $match, array $aggregates, \Illuminate\Support\Carbon $closedAt): string
    {
        return Pdf::loadView('matches.survey.report-pdf', [
            'match' => $match,
            'aggregates' => $aggregates,
            'closedAt' => $closedAt,
            'ratingLabels' => self::ratingLabels(),
            'commentSections' => self::commentSections(),
            'ratingMin' => MatchSurveyService::RATING_MIN,
            'ratingMax' => MatchSurveyService::RATING_MAX,
        ])
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * Load every role-eligible staff user once so a daily batch of many
     * matches doesn't re-query User::role() per match.
     *
     * @return Collection<int, User>
     */
    private function staffRecipients(): Collection
    {
        return User::query()
            ->whereNotNull('email_verified_at')
            ->whereHas('roles', fn ($q) =>
                $q->whereIn('name', ['exco', 'owner', 'admin'])
            )
            ->get()
            ->unique('id');
    }

    /**
     * @param  Collection<int, User>  $staff
     * @return Collection<int, User>
     */
    private function recipientsFor(MatchEvent $match, Collection $staff): Collection
    {
        $directors = collect();
        if ($match->created_by) {
            /** @var User|null $md */
            $md = User::query()
                ->whereKey($match->created_by)
                ->whereNotNull('email_verified_at')
                ->first();
            if ($md !== null) {
                $directors->push($md);
            }
        }

        return $directors->concat($staff)->unique('id')->values();
    }

    /**
     * Short labels for the PDF + summary email. Numbered to match the
     * WhatsApp-approved scorecard.
     *
     * @return array<string, string>
     */
    public static function ratingLabels(): array
    {
        return [
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
    }

    /**
     * @return array<string, array{title: string, class: string}>
     */
    public static function commentSections(): array
    {
        return [
            'highlight' => ['title' => '14. Favourite aspect', 'class' => 'highlight'],
            'improvement' => ['title' => '15. One thing to improve', 'class' => 'improvement'],
            'other_comments' => ['title' => '16. Other comments, suggestions, criticism', 'class' => 'default'],
        ];
    }
}
