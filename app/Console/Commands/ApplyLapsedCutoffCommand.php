<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Notifications\MembershipExpiredNotification;
use App\Services\AuditLogService;
use App\Services\MembershipValidationService;
use Illuminate\Console\Command;

/**
 * One-off backfill for the 90-day lapsed cutoff.
 *
 * Members whose membership expired more than LAPSED_CUTOFF_DAYS ago should
 * carry status='expired' in the DB (cleaner admin panel + audit trail) and
 * be told once that their next match entry now costs the full non-member
 * rate. ExpireMembershipsJob dispatches that notice going forward — this
 * command sweeps the members who crossed the threshold BEFORE the feature
 * shipped so they aren't blindsided at the next registration.
 *
 * Idempotent:
 *   - Only touches memberships still on status='lapsed'. Anything already
 *     status='expired' is skipped, so re-running is safe.
 *   - Revoked / free-type memberships are never touched.
 */
class ApplyLapsedCutoffCommand extends Command
{
    protected $signature = 'saprf:apply-lapsed-cutoff
        {--dry-run : Report what would change without writing}
        {--skip-email : Flip statuses but do not dispatch MembershipExpiredNotification}';

    protected $description = 'Backfill: flip long-lapsed memberships to status=expired and send the once-off "you are past the grace window" notice.';

    public function handle(AuditLogService $audit): int
    {
        $cutoff = now()->startOfDay()->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS);
        $dryRun = (bool) $this->option('dry-run');
        $skipEmail = (bool) $this->option('skip-email');

        $memberships = Membership::query()
            ->where('membership_type', '!=', 'free')
            ->where('status', 'lapsed')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', $cutoff)
            ->with('user')
            ->get();

        $this->info('=== saprf:apply-lapsed-cutoff ===');
        $this->line('Cutoff date: ' . $cutoff->toDateString() . ' (LAPSED_CUTOFF_DAYS = ' . MembershipValidationService::LAPSED_CUTOFF_DAYS . ')');
        $this->line('Dry run: ' . ($dryRun ? 'YES (nothing will be written)' : 'no'));
        $this->line('Skip email: ' . ($skipEmail ? 'YES' : 'no'));
        $this->line('Candidates: ' . $memberships->count());
        $this->newLine();

        if ($memberships->isEmpty()) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $flipped = 0;
        $queued = 0;
        $noUser = 0;

        foreach ($memberships as $membership) {
            $line = sprintf(
                '  #%d SAPRF %s — %s (expired %s, %d days ago)',
                $membership->id,
                $membership->saprf_number ?? '—',
                $membership->user?->name ?? '(no user)',
                $membership->expiry_date->toDateString(),
                (int) now()->startOfDay()->diffInDays($membership->expiry_date),
            );

            if ($dryRun) {
                $this->line('would flip ' . $line);

                continue;
            }

            $previous = $membership->status;
            $membership->update(['status' => 'expired']);

            $audit->log(
                null,
                'membership.marked_expired',
                'Membership',
                $membership->id,
                ['status' => $previous],
                ['status' => 'expired', 'expired_on' => $membership->expiry_date->toDateString()],
                'Applied 90-day lapsed cutoff (expired ' . $membership->expiry_date->format('d M Y') . ')',
            );
            $flipped++;

            if ($skipEmail) {
                continue;
            }

            if (! $membership->user) {
                $noUser++;

                continue;
            }

            $membership->user->notify(new MembershipExpiredNotification($membership));
            $queued++;
        }

        $this->newLine();

        if ($dryRun) {
            $this->info('Dry run complete. Nothing was written.');

            return self::SUCCESS;
        }

        $this->info("Flipped: {$flipped}");
        if (! $skipEmail) {
            $this->info("Queued for email: {$queued}");
            if ($noUser > 0) {
                $this->warn("Skipped (no linked user): {$noUser}");
            }
        }

        return self::SUCCESS;
    }
}
