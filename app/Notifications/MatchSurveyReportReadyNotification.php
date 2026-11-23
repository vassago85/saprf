<?php

namespace App\Notifications;

use App\Models\MatchEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Closing-window report for one match.
 *
 * Sent by SendClosedMatchSurveyReportsJob once the 5-day post-match
 * survey window has elapsed. Carries:
 *   - a short inline summary (response rate, overall average,
 *     highest-rated and lowest-rated category) so a reader skimming
 *     the inbox already knows the shape of the result,
 *   - a PDF attachment with the full aggregated report (histograms,
 *     category averages, shuffled comments).
 *
 * The per-shooter answers are never included — the two-table
 * anonymity split from MatchSurveyService is preserved end-to-end.
 */
class MatchSurveyReportReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $summary  Headline numbers (responded, eligible, response_rate, overall_average, top/low)
     * @param  string  $pdfContents             Binary PDF body — small, so passed inline rather than written to disk first
     */
    public function __construct(
        private readonly MatchEvent $match,
        private readonly array $summary,
        private readonly string $pdfContents,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function middleware(): array
    {
        return [new RateLimited('mail')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $greetingName = $notifiable->name ?? 'there';
        $responded = $this->summary['responded'] ?? 0;
        $eligible = $this->summary['eligible'] ?? 0;
        $rate = $this->summary['response_rate'];
        $overall = $this->summary['overall_average'];
        $topLabel = $this->summary['top_label'] ?? null;
        $topScore = $this->summary['top_score'] ?? null;
        $lowLabel = $this->summary['low_label'] ?? null;
        $lowScore = $this->summary['low_score'] ?? null;

        $filename = $this->pdfFilename();

        $mail = (new MailMessage)
            ->subject('Post-match survey closed · '.$this->match->name)
            ->greeting('Hi '.$greetingName.',')
            ->line('The shooter feedback survey for **'.$this->match->name.'** has closed. The full anonymous report is attached as a PDF.');

        if ($responded === 0) {
            $mail->line('No shooters submitted feedback before the window closed.');
        } else {
            $mail->line(sprintf(
                '**Response rate:** %d of %d shooters (%s%%).',
                $responded,
                $eligible,
                $rate !== null ? number_format($rate, 1) : '—',
            ));

            if ($overall !== null) {
                $mail->line('**Overall average:** '.number_format($overall, 2).' / 10.');
            }
            if ($topLabel && $topScore !== null) {
                $mail->line('**Top rated:** '.$topLabel.' ('.number_format($topScore, 2).').');
            }
            if ($lowLabel && $lowScore !== null) {
                $mail->line('**Lowest rated:** '.$lowLabel.' ('.number_format($lowScore, 2).').');
            }

            $mail->line('Open the PDF for the full category breakdown and the three sets of shuffled comments (favourite aspect, one thing to improve, other comments).');
        }

        $mail->line('_Anonymity reminder: SAPRF stored who replied separately from what they said, so this report is aggregated across all respondents with no way to attribute a specific answer to a specific shooter._')
            ->salutation('Thanks for running a great event.');

        $mail->attachData($this->pdfContents, $filename, [
            'mime' => 'application/pdf',
        ]);

        return $mail;
    }

    private function pdfFilename(): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', trim((string) $this->match->name)) ?: 'match';
        $slug = trim($slug, '-') ?: 'match';

        return 'SAPRF-Survey-'.$slug.'-'.$this->match->id.'.pdf';
    }
}
