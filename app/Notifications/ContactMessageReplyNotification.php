<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Staff reply to a /contact enquiry, sent via Mailgun to the enquirer.
 * Reply-To is the secretary inbox so any follow-up lands back in the
 * shared triage inbox rather than the individual admin's personal mail.
 */
class ContactMessageReplyNotification extends Notification
{
    public function __construct(
        private readonly ContactMessage $contactMessage,
        private readonly string $replyBody,
        private readonly User $repliedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $enquiry = $this->contactMessage;
        $secretary = app(SettingsService::class)->replyToEmail();

        $message = (new MailMessage)
            ->subject('Re: '.$enquiry->subject)
            ->greeting('Hello '.$enquiry->first_name)
            ->line($this->replyBody)
            ->line('---')
            ->line('Your original message:')
            ->line($enquiry->message)
            ->salutation('Regards,'."\n".$this->repliedBy->name);

        if ($secretary) {
            $message->replyTo($secretary);
        }

        $message->withSymfonyMessage(function (Email $email) use ($enquiry): void {
            $email->getHeaders()->addTextHeader(
                'X-Mailgun-Variables',
                json_encode(['contact_message_id' => $enquiry->id], JSON_UNESCAPED_SLASHES),
            );
        });

        return $message;
    }
}
