<?php

namespace App\Notifications;

use App\Models\MatchRegistration;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\URL;

/**
 * MD/admin-triggered nudge for a shooter whose match entry fee is still
 * unpaid on the SAPRF platform — most commonly seen after a match import
 * when scores land for someone who shot but never completed checkout on
 * the site (or paid via the legacy platform).
 *
 * The email presents the shooter two clear paths:
 *
 *   1. A signed "I paid on the old site" confirm link. Signature-based,
 *      no session required. Landing page (see
 *      {@see \App\Http\Controllers\RegistrationController::showOldSitePaymentConfirmation})
 *      forces a second click before flipping the row to `waived` so a
 *      forwarded email cannot silently self-confirm.
 *
 *   2. A "Pay now" link that drops them into the standard registration
 *      view where the outstanding-payment CTA is already wired to
 *      PayFast. That route is auth-gated, so the shooter is asked to
 *      sign in first — which is what we want, since PayFast receipts
 *      have to be attached to the payer's account.
 *
 * Reply-To is set to the sender (MD) so a shooter who paid off-platform
 * some other way (EFT, cash, comp) can just reply and the MD's own
 * inbox handles it without the shooter needing to know a support
 * address.
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

        // 30-day signed link — long enough to survive the shooter
        // ignoring the mail over a weekend or two, short enough that
        // stale copies of the email cannot be redeemed months later
        // when a policy change might have made "waived" wrong.
        $confirmUrl = URL::temporarySignedRoute(
            'registrations.confirm-old-site-payment.show',
            now()->addDays(30),
            ['registration' => $registration->id],
        );

        $payUrl = route('registrations.show', $registration);

        $fee = number_format((float) $registration->fee_amount, 2);
        $shooterName = $notifiable->name ?? $registration->shooter_name ?? 'shooter';

        $message = (new MailMessage)
            ->subject('Outstanding entry fee for ' . $match->name)
            ->greeting('Hi ' . $shooterName . ',')
            ->line('Our records show you shot **' . $match->name . '** on '
                . $match->match_date->format('d M Y')
                . ', but the SAPRF platform hasn\'t received your entry fee of **R' . $fee . '**.')
            ->line('There are two ways to sort this out:')
            ->line('**1. If you paid via the previous SAPRF site**, click the button below and confirm on the landing page. We\'ll mark your entry as settled.')
            ->action('I already paid on the old site', $confirmUrl)
            ->line('**2. If you still need to pay**, sign in and complete the payment for this entry here:')
            ->line($payUrl)
            ->line('If neither applies (for example, you paid by EFT or cash), just reply to this email — it goes straight to '
                . $this->sender->name . '.');

        if ($this->sender->email) {
            $message->replyTo($this->sender->email, $this->sender->name);
        }

        return $message->salutation('Thanks, ' . $this->sender->name);
    }
}
