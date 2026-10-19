<?php

namespace App\Notifications;

use App\Models\MatchRegistration;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * MD/admin-triggered nudge for a shooter whose match entry fee is still
 * unpaid on the SAPRF platform — typically after they registered and shot
 * but never completed (or never showed) payment on the platform.
 *
 * The email offers:
 *   1. Pay now via PayFast (sign in → registration page).
 *   2. If they already paid — reply to the MD with either a proof of
 *      payment (POP) or a screenshot of their PayFast confirmation email.
 *      The MD/admin then marks the entry settled on the platform.
 *
 * Reply-To is the sender (MD/admin) so POP attachments land in their inbox.
 */
class PaymentInquiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  MatchRegistration  $registration  The unpaid registration
     *                                           being asked about.
     * @param  User  $sender  The MD or admin who clicked "send inquiry"
     *                       — used for Reply-To and the closing line.
     */
    public function __construct(
        private readonly MatchRegistration $registration,
        private readonly User $sender,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Route every send through the shared "mail" limiter (50/hour,
     * 2/min). This is not auth-critical mail, so no bypass.
     */
    public function middleware(): array
    {
        return [new RateLimited('mail')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $registration = $this->registration->loadMissing('match');
        $match = $registration->match;

        $payUrl = route('registrations.show', $registration);

        $fee = number_format((float) $registration->fee_amount, 2);
        $shooterName = $notifiable->name ?? $registration->shooter_name ?? 'shooter';
        $senderName = $this->sender->name ?? 'your match director';

        $message = (new MailMessage)
            ->subject('Outstanding entry fee for '.$match->name)
            ->greeting('Hi '.$shooterName.',')
            ->line('Our records show you shot **'.$match->name.'** on '
                .$match->match_date->format('d M Y')
                .', but the SAPRF platform hasn\'t received your entry fee of **R'.$fee.'**.')
            ->line('Please sort this out in one of these ways:')
            ->line('**1. Still need to pay?** Sign in and complete payment with PayFast:')
            ->action('Pay now', $payUrl)
            ->line('**2. Already paid?** Reply to this email (it goes to '.$senderName.') and attach either:')
            ->line('• Your **proof of payment (POP)**, or')
            ->line('• A **screenshot of the PayFast confirmation email** you received.')
            ->line('Once we have that, we\'ll mark your entry as settled on the platform.')
            ->line('If you\'re unsure who to contact, reply here or email SAPRF admin — we\'ll help.');

        if ($this->sender->email) {
            $message->replyTo($this->sender->email, $this->sender->name);
        }

        return $message->salutation('Thanks, '.$senderName);
    }
}
