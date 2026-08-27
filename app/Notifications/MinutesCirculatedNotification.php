<?php

namespace App\Notifications;

use App\Models\ExcoMeeting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Sent to every ExCo member (and Chair) when the draft minutes of a
 * closed sitting are circulated for review. Contains the meeting
 * summary + a deep link to the meeting's page on the platform where
 * they can read the full minutes and submit proposed amendments.
 *
 * Queued so a slow Mailgun response doesn't block the "Mark as
 * circulated" click.
 */
class MinutesCirculatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ExcoMeeting $meeting,
        private readonly User $circulatedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $meeting = $this->meeting;
        $showUrl = route('exco.meetings.show', $meeting);
        $printUrl = route('exco.meetings.minutes.print', $meeting);
        $secretary = app(SettingsService::class)->replyToEmail();

        $agendaCount = $meeting->agendaItems()->count();
        $when = $meeting->scheduled_at->format('D d M Y H:i');

        $message = (new MailMessage)
            ->subject('Draft minutes for review — '.$meeting->title)
            ->greeting('Hi '.($notifiable->name ?? 'ExCo'))
            ->line('The draft minutes of the following ExCo sitting have been circulated for your review:')
            ->line('**'.$meeting->title.'**')
            ->line($when.' · '.$agendaCount.' agenda item'.($agendaCount === 1 ? '' : 's'))
            ->line('Please read them carefully and submit any proposed amendments on the platform. Once every member has had a chance to respond, the minutes will be formally adopted at the next sitting.')
            ->action('Open minutes on the platform', $showUrl)
            ->line('You can also open a printable / PDF version: '.$printUrl)
            ->line('Circulated by '.$this->circulatedBy->name.'.');

        if ($secretary) {
            $message->replyTo($secretary);
        }

        // Mailgun variables let webhook events (delivered / failed) be
        // correlated back to the meeting on the email_logs page.
        $meetingId = $meeting->id;
        $message->withSymfonyMessage(function (Email $email) use ($meetingId): void {
            $email->getHeaders()->addTextHeader(
                'X-Mailgun-Variables',
                json_encode(['exco_meeting_id' => $meetingId], JSON_UNESCAPED_SLASHES),
            );
        });

        return $message;
    }
}
