<?php

namespace App\Jobs;

use App\Models\Membership;
use App\Notifications\MembershipExpiredNotification;
use App\Notifications\MembershipExpiringSoonNotification;
use App\Notifications\MembershipLapsedNotification;
use App\Services\AuditLogService;
use App\Services\MembershipValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpireMembershipsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const REMINDER_DAYS = [30, 7];

    public function handle(AuditLogService $audit): void
    {
        $this->expireOverdueMemberships($audit);
        $this->sendExpiryReminders();
        $this->sendLapsedCutoffNotices();
    }

    private function expireOverdueMemberships(AuditLogService $audit): void
    {
        $today = now()->startOfDay();

        $expiring = Membership::query()
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today)
            ->with('user')
            ->get();

        foreach ($expiring as $membership) {
            $previousStatus = $membership->status;

            $membership->update([
                'status' => 'lapsed',
            ]);

            $audit->log(
                null,
                'membership.auto_lapsed',
                'Membership',
                $membership->id,
                ['status' => $previousStatus],
                ['status' => 'lapsed', 'expired_on' => $membership->expiry_date?->toDateString()],
                "Membership {$membership->saprf_number} auto-lapsed (expired " . $membership->expiry_date?->format('d M Y') . ')',
            );

            if ($membership->user) {
                try {
                    $membership->user->notify(new MembershipLapsedNotification($membership));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send lapsed notification', [
                        'membership_id' => $membership->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function sendExpiryReminders(): void
    {
        $today = now()->startOfDay();

        foreach (self::REMINDER_DAYS as $days) {
            $target = $today->copy()->addDays($days)->toDateString();

            $memberships = Membership::query()
                ->where('status', 'active')
                ->whereDate('expiry_date', $target)
                ->with('user')
                ->get();

            foreach ($memberships as $membership) {
                if (! $membership->user) {
                    continue;
                }

                try {
                    $membership->user->notify(new MembershipExpiringSoonNotification($membership, $days));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send expiring-soon notification', [
                        'membership_id' => $membership->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Notify shooters whose membership crosses the lapsed-fee cutoff today.
     * From tomorrow onwards their next entry is priced at the non-member
     * rate rather than the lapsed-member surcharge — this is the one and
     * only "you're now fully expired" warning.
     *
     * Idempotency: the query is keyed on the exact cutoff date, so the
     * daily job hits each membership at most once. If the job misses a
     * day (server down, redeploy at 02:00) that cohort skips this mail —
     * same trade-off `sendExpiryReminders` accepts for -30/-7 reminders.
     */
    private function sendLapsedCutoffNotices(): void
    {
        $target = now()->startOfDay()
            ->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS)
            ->toDateString();

        $memberships = Membership::query()
            ->where('status', 'lapsed')
            ->whereDate('expiry_date', $target)
            ->with('user')
            ->get();

        foreach ($memberships as $membership) {
            if (! $membership->user) {
                continue;
            }

            try {
                $membership->user->notify(new MembershipExpiredNotification($membership));
            } catch (\Throwable $e) {
                Log::warning('Failed to send lapsed-cutoff notification', [
                    'membership_id' => $membership->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
