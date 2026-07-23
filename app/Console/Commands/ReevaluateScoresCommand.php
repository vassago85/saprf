<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Score;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use Illuminate\Console\Command;

/**
 * Re-run membership-based score validation across every score, then rebuild the
 * season standings. Use this after changing the validation rules (e.g. treating
 * "free" registrants as non-members) so already-imported data is brought in line
 * without re-importing anything.
 *
 * Steps:
 *   1. (unless --skip-free-fix) force every "free" membership to payment_status
 *      = unpaid, so the UI stops showing forced-registration guests as paid.
 *   2. (unless --skip-expiry-fix) flip any active membership whose expiry_date is
 *      in the past to status = expired, so the roster stops showing lapsed people
 *      as active/paid. Done silently (no lapse emails), unlike ExpireMembershipsJob.
 *   3. Re-evaluate every score's status (valid / pending / lapsed / non_member).
 *      Historical scores are safe: validity is tied to the match date's paid
 *      window, not the membership's current status label.
 *   4. Recalculate national + per-province season standings for each series.
 */
class ReevaluateScoresCommand extends Command
{
    protected $signature = 'scores:reevaluate
        {--skip-free-fix : Do not touch free memberships payment_status}
        {--skip-expiry-fix : Do not expire memberships whose expiry_date has passed}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Reconcile membership status (free/expired) and rebuild season standings from valid scores';

    public function handle(
        ScoreValidationService $scoreValidation,
        StandingsCalculationService $standings,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== scores:reevaluate ===');
        $this->line('Dry run: '.($dryRun ? 'YES (nothing will be written)' : 'no'));
        $this->newLine();

        if (! $this->option('skip-free-fix')) {
            // Legacy imports sometimes stamped real paid members as type=free.
            // Promote those (real SAPRF # + paid + unexpired) to type=paid first.
            $promoteQuery = Membership::where('membership_type', 'free')
                ->whereIn('payment_status', ['paid', 'waived'])
                ->whereNotNull('saprf_number')
                ->where('saprf_number', 'not like', 'SAPRF-IMPORT-%')
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', now()->startOfDay());
            $promoteCount = $promoteQuery->count();
            if ($dryRun) {
                $this->line("Would promote {$promoteCount} free→paid membership(s) that look like real paid members.");
            } else {
                $promoteQuery->update(['membership_type' => 'paid']);
                $this->line("Promoted {$promoteCount} free→paid membership(s) (real SAPRF # + paid + unexpired).");
            }

            // Remaining free rows with a paid flag are forced-registration guests.
            $freeQuery = Membership::where('membership_type', 'free')
                ->where('payment_status', '!=', 'unpaid');
            $freeCount = $freeQuery->count();
            if ($dryRun) {
                $this->line("Would mark {$freeCount} free membership(s) as unpaid.");
            } else {
                $freeQuery->update(['payment_status' => 'unpaid']);
                $this->line("Marked {$freeCount} free membership(s) as unpaid.");
            }
        }

        if (! $this->option('skip-expiry-fix')) {
            $expiredQuery = Membership::where('status', 'active')
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<', now()->startOfDay());
            $expiredCount = $expiredQuery->count();
            if ($dryRun) {
                $this->line("Would mark {$expiredCount} active membership(s) with a past expiry as expired.");
            } else {
                $expiredQuery->update(['status' => 'expired']);
                $this->line("Marked {$expiredCount} membership(s) as expired (expiry date already passed).");
            }
        }

        $total = Score::count();
        $this->info("Re-evaluating {$total} score(s)...");

        $changed = 0;
        $statusTally = [];

        if (! $dryRun) {
            Score::query()
                ->with(['user.membership', 'match'])
                ->chunkById(200, function ($scores) use ($scoreValidation, &$changed, &$statusTally) {
                    foreach ($scores as $score) {
                        $before = $score->status;
                        $scoreValidation->evaluateScoreStatus($score);
                        if ($score->status !== $before) {
                            $changed++;
                        }
                        $statusTally[$score->status] = ($statusTally[$score->status] ?? 0) + 1;
                    }
                });
            $this->line("  {$changed} score(s) changed status.");
            foreach ($statusTally as $status => $count) {
                $this->line("    {$status}: {$count}");
            }
        } else {
            $this->line('  [dry-run] skipping per-score re-evaluation.');
        }

        $this->info('Recalculating season standings...');
        $combos = MatchEvent::query()
            ->whereNotNull('series')
            ->whereNotNull('season')
            ->select('series', 'season')
            ->distinct()
            ->get();

        foreach ($combos as $combo) {
            $this->line("  {$combo->series} {$combo->season} (national)");
            if (! $dryRun) {
                $standings->recalculateSeasonStandings($combo->series, $combo->season, null);
            }

            $provinceIds = MatchEvent::where('series', $combo->series)
                ->where('season', $combo->season)
                ->whereNotNull('province_id')
                ->distinct()
                ->pluck('province_id');

            foreach ($provinceIds as $provinceId) {
                if (! $dryRun) {
                    $standings->recalculateSeasonStandings($combo->series, $combo->season, $provinceId);
                }
            }
            $this->line("    + {$provinceIds->count()} province table(s)");
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete.' : 'Done.');

        return self::SUCCESS;
    }
}
