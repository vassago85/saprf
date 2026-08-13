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
 *   4. (unless --skip-match-ranking) recompute per-match rankings — normalized_score,
 *      division_normalized_score, overall_rank, division_rank — so any Score row
 *      that drifted out of sync (raw edited post-import, division corrected,
 *      status flipped) is brought back in line with the current visible set.
 *      Without this step, season aggregation reads whatever stale numbers were
 *      persisted, and running the command produces the same wrong totals every
 *      time.
 *   5. Recalculate national + per-province season standings for each series.
 */
class ReevaluateScoresCommand extends Command
{
    protected $signature = 'scores:reevaluate
        {--user= : Re-evaluate only this user ID and rebuild just their affected matches (skips global fixes)}
        {--skip-free-fix : Do not touch free memberships payment_status}
        {--skip-expiry-fix : Do not expire memberships whose expiry_date has passed}
        {--skip-match-ranking : Do not recompute per-match normalized scores and ranks}
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

        // Scoped single-shooter path: re-evaluate just this user's scores and
        // rebuild only the matches whose status changed. Used to reconcile one
        // shooter after an admin corrects their membership (e.g. backdated
        // start_date / extended expiry) without sweeping the whole table.
        if ($this->option('user') !== null) {
            return $this->handleSingleUser((int) $this->option('user'), $scoreValidation, $standings, $dryRun);
        }

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

        // Re-rank every completed match's scores BEFORE aggregating season
        // standings. Season aggregation reads persisted normalized_score /
        // division_normalized_score off each Score row — if a raw score, a
        // shooter's division, or a status was edited after the initial rank
        // pass without a manual rerank, those persisted values are stale and
        // every subsequent aggregation reproduces the wrong totals. Re-ranking
        // here guarantees the season builder always starts from fresh
        // per-match numbers.
        if (! $this->option('skip-match-ranking')) {
            $rankableMatches = MatchEvent::query()
                ->where('status', 'completed')
                ->orderBy('match_date')
                ->get();

            $this->info('Re-ranking '.$rankableMatches->count().' completed match(es)...');
            if (! $dryRun) {
                $bar = $this->output->createProgressBar($rankableMatches->count());
                $bar->start();
                foreach ($rankableMatches as $rankableMatch) {
                    $standings->calculateMatchRankings($rankableMatch);
                    $bar->advance();
                }
                $bar->finish();
                $this->newLine();
            } else {
                $this->line('  [dry-run] skipping match re-ranking.');
            }
        } else {
            $this->line('Skipping match re-ranking (--skip-match-ranking).');
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

            // Provincial standings follow the shooter's home province, so every
            // province is a candidate table regardless of where matches were held.
            $provinceIds = \App\Models\Province::query()->pluck('id');

            foreach ($provinceIds as $provinceId) {
                if (! $dryRun) {
                    $standings->recalculateSeasonStandings($combo->series, $combo->season, (int) $provinceId);
                }
            }
            $this->line("    + {$provinceIds->count()} province table(s)");
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete.' : 'Done.');

        return self::SUCCESS;
    }

    private function handleSingleUser(
        int $userId,
        ScoreValidationService $scoreValidation,
        StandingsCalculationService $standings,
        bool $dryRun,
    ): int {
        $user = \App\Models\User::with('membership')->find($userId);
        if (! $user) {
            $this->error("No user found with ID {$userId}.");

            return self::FAILURE;
        }

        $this->info("Scoped re-evaluation for #{$user->id} — {$user->name}");
        $membership = $user->membership;
        $this->line('  Membership: '.($membership
            ? "type={$membership->membership_type}, payment={$membership->payment_status}, status={$membership->status}, "
                .'window='.($membership->start_date?->toDateString() ?? 'null').' → '.($membership->expiry_date?->toDateString() ?? 'null')
            : 'none on file'));

        $scores = Score::with('match')->where('user_id', $userId)->get();
        $this->info("Found {$scores->count()} score(s).");

        if ($dryRun) {
            $membershipService = app(\App\Services\MembershipValidationService::class);
            foreach ($scores as $score) {
                $wouldBeValid = $membershipService->isUserValidForOfficialPurposes($user, \Carbon\Carbon::parse($score->match_date));
                $this->line(sprintf(
                    '  [%s] %s — current: %s → would be valid: %s',
                    $score->match_date,
                    $score->match?->name ?? 'match #'.$score->match_id,
                    $score->status,
                    $wouldBeValid ? 'YES' : 'no',
                ));
            }
            $this->newLine();
            $this->info('Dry run complete — nothing written.');

            return self::SUCCESS;
        }

        $affected = $scoreValidation->reevaluateScoresForUser($userId);
        $this->line('  '.count($affected).' match(es) had a score status change.');

        foreach (MatchEvent::whereIn('id', $affected)->get() as $match) {
            $this->line("  Rebuilding standings for: {$match->name}");
            $standings->recalculateForMatch($match);
        }

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
