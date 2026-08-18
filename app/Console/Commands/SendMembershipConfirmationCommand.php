<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Notifications\MembershipConfirmedNotification;
use App\Services\AuditLogService;
use Illuminate\Console\Command;

/**
 * Manually re-send the "Your SAPRF Membership is Active" email for a single
 * membership. Use when a record was activated outside the normal PayFast/join
 * flow — e.g. an admin fixed the type on the edit screen — and the shooter
 * therefore never received the standard welcome notification.
 *
 * Looks up the latest confirmed MembershipPayment so the email includes the
 * amount and reference the shooter actually paid. Passes `null` when no
 * MembershipPayment row exists (waived or backfilled memberships), in which
 * case the notification quietly omits the amount/reference lines.
 */
class SendMembershipConfirmationCommand extends Command
{
    protected $signature = 'membership:send-confirmation
                            {membership : Membership ID or SAPRF number}
                            {--dry-run : Show what would be sent without dispatching the email}';

    protected $description = 'Re-send the membership activation email for one member';

    public function handle(AuditLogService $auditLogService): int
    {
        $identifier = (string) $this->argument('membership');

        $membership = ctype_digit($identifier)
            ? Membership::find((int) $identifier)
            : Membership::where('saprf_number', $identifier)->first();

        if (! $membership) {
            $this->error("Membership not found: {$identifier}");

            return self::FAILURE;
        }

        $membership->load('user', 'payments');

        if (! $membership->user) {
            $this->error("Membership {$membership->id} has no linked user — cannot send email.");

            return self::FAILURE;
        }

        if (! $membership->user->email) {
            $this->error("User {$membership->user->id} has no email address on file.");

            return self::FAILURE;
        }

        $payment = $membership->payments()
            ->where('status', 'confirmed')
            ->latest('payment_date')
            ->first();

        $this->line('About to send confirmation email:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Member', $membership->user->name],
                ['Email', $membership->user->email],
                ['SAPRF #', $membership->saprf_number],
                ['Type', $membership->membership_type],
                ['Expires', $membership->expiry_date?->format('Y-m-d') ?? '—'],
                ['Payment ref', $payment?->payment_reference ?? '—'],
                ['Amount', $payment ? 'R '.number_format((float) $payment->amount, 2) : '—'],
            ],
        );

        if ($this->option('dry-run')) {
            $this->warn('Dry run — not sending. Re-run without --dry-run to actually dispatch.');

            return self::SUCCESS;
        }

        try {
            $membership->user->notify(new MembershipConfirmedNotification($membership, $payment));
        } catch (\Throwable $e) {
            $this->error('Failed to queue notification: '.$e->getMessage());

            return self::FAILURE;
        }

        $auditLogService->log(
            null,
            'membership.confirmation.resent',
            'Membership',
            $membership->id,
            null,
            [
                'to' => $membership->user->email,
                'payment_reference' => $payment?->payment_reference,
                'via' => 'artisan membership:send-confirmation',
            ],
        );

        $this->info("Queued confirmation email for {$membership->user->email}.");

        return self::SUCCESS;
    }
}
