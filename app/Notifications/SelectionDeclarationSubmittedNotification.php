<?php

namespace App\Notifications;

use App\Models\SelectionAthlete;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Emailed to ExCo / owner / developer when a shooter submits the online
 * Eligibility-to-Compete + intention-to-participate form. The rule ELG-05
 * (PR22) / ELG-06 (PRS) treats the form as "received by ExCo" the moment the
 * shooter clicks submit, so this notification is the human-facing counterpart
 * of the audit-log entry — a heads-up rather than a permission gate.
 */
class SelectionDeclarationSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SelectionAthlete $athlete) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Route every send through the shared "mail" limiter (50/hour, 2/min).
     * Auth-critical mail (OTP, password reset) skips this limiter.
     */
    public function middleware(): array
    {
        return [new RateLimited('mail')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $athlete = $this->athlete;
        $cycle = $athlete->cycle;
        $user = $athlete->user;
        $adminUrl = url(route('selection.cycles.athletes.show', [$cycle, $athlete]));

        return (new MailMessage)
            ->subject("Eligibility to Compete form received: {$user?->name} · {$cycle?->series} {$cycle?->season}")
            ->greeting('New Eligibility to Compete submission')
            ->line("**Shooter:** {$user?->name} <{$user?->email}>")
            ->line("**Cycle:** {$cycle?->series} {$cycle?->season}")
            ->line("**Submitted at:** ".optional($athlete->declaration?->submitted_at)->format('Y-m-d H:i'))
            ->line('The shooter has confirmed intention to participate and completed the Eligibility to Compete attestations. This satisfies the ExCo-receipt requirement for this cycle.')
            ->action('Open athlete record', $adminUrl);
    }
}
