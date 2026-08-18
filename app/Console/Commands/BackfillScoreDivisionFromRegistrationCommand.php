<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Score;
use App\Services\StandingsCalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Copy a shooter's registered division across to their Score row when the
 * score row is missing it (or, with an explicit flag, when it disagrees
 * with what the shooter registered under).
 *
 * Why this exists
 * ---------------
 * Historical score-import flows landed with `division_id = null` on many
 * Score rows. The standings calculator reads Score.division_id, not the
 * shooter's User.division_id, so those blank rows never appear in the
 * per-division standings — even though the shooter clearly signed up under
 * a division. The fix is to backfill Score.division_id from the shooter's
 * non-cancelled MatchRegistration for the same match.
 *
 * Rules
 * -----
 *   - Only rewrites Score.division_id when it's currently NULL. A non-null
 *     value is preserved unless --overwrite-mismatches is passed, because
 *     an admin may have hand-corrected the division after the fact and we
 *     don't want to silently clobber that.
 *   - Ignores cancelled registrations. If a shooter withdrew and re-entered
 *     under a different division, the most recent non-cancelled row wins.
 *   - Prints a mismatch warning for every conflict so admins can review the
 *     list even when they didn't pass --overwrite-mismatches.
 *   - Recalculates standings for each touched match unless --skip-standings.
 *   - Writes an AuditLog per touched match summarising what changed.
 */
class BackfillScoreDivisionFromRegistrationCommand extends Command
{
    protected $signature = 'scores:backfill-division-from-registration
        {match? : Optional MatchEvent ID; without it the command scans every match with at least one score-with-null-division}
        {--dry-run : Report what would change without writing}
        {--overwrite-mismatches : Also overwrite scores whose division disagrees with the registration (default: warn and skip)}
        {--skip-standings : Skip StandingsCalculationService::recalculateForMatch after writing}';

    protected $description = 'Backfill Score.division_id from each shooter\'s MatchRegistration for the same match';

    public function handle(StandingsCalculationService $standings): int
    {
        $matchIdArg = $this->argument('match');
        $dryRun = (bool) $this->option('dry-run');
        $overwriteMismatches = (bool) $this->option('overwrite-mismatches');
        $skipStandings = (bool) $this->option('skip-standings');

        $matches = $this->resolveMatches($matchIdArg);
        if ($matches->isEmpty()) {
            $this->info('No matches with missing Score.division_id — nothing to do.');
            return self::SUCCESS;
        }

        $this->info('=== Backfill Score.division_id from MatchRegistration ===');
        $this->line('Mode:              '.($dryRun ? 'DRY RUN (no writes)' : 'LIVE'));
        $this->line('Mismatch handling: '.($overwriteMismatches ? 'OVERWRITE existing division_id' : 'WARN and skip (keep existing)'));
        $this->line('Matches to scan:   '.$matches->count());
        $this->newLine();

        $grandTotal = [
            'filled' => 0,
            'skipped_no_registration' => 0,
            'skipped_mismatch' => 0,
            'overwritten_mismatch' => 0,
        ];

        foreach ($matches as $match) {
            $matchTotal = $this->processMatch(
                $match,
                $dryRun,
                $overwriteMismatches,
                $skipStandings,
                $standings,
            );

            foreach ($matchTotal as $key => $value) {
                $grandTotal[$key] += $value;
            }
        }

        $this->newLine();
        $this->info('=== Overall totals ===');
        $this->info("Filled: {$grandTotal['filled']}   Overwritten: {$grandTotal['overwritten_mismatch']}   Skipped-no-registration: {$grandTotal['skipped_no_registration']}   Skipped-mismatch: {$grandTotal['skipped_mismatch']}");

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int,MatchEvent>
     */
    private function resolveMatches(mixed $matchIdArg): \Illuminate\Support\Collection
    {
        if ($matchIdArg !== null) {
            $match = MatchEvent::find((int) $matchIdArg);
            if (! $match) {
                $this->error("Match #{$matchIdArg} does not exist.");
                return collect();
            }
            return collect([$match]);
        }

        // Unscoped run: any match with at least one score-with-null-division.
        $matchIds = Score::whereNull('division_id')->distinct()->pluck('match_id');

        return MatchEvent::whereIn('id', $matchIds)->orderBy('match_date')->get();
    }

    /**
     * @return array{filled:int,skipped_no_registration:int,skipped_mismatch:int,overwritten_mismatch:int}
     */
    private function processMatch(
        MatchEvent $match,
        bool $dryRun,
        bool $overwriteMismatches,
        bool $skipStandings,
        StandingsCalculationService $standings,
    ): array {
        $this->line("--- Match #{$match->id}: {$match->name} ({$match->match_date?->format('Y-m-d')}) ---");

        // Load candidate scores. When overwriting mismatches, we also need
        // rows that HAVE a division but disagree with their registration —
        // so we can't just filter by whereNull('division_id') up front.
        $scores = Score::where('match_id', $match->id)
            ->whereNotNull('user_id')
            ->get(['id', 'user_id', 'shooter_name', 'division_id']);

        if ($scores->isEmpty()) {
            $this->line('  No scored shooters. Skipping.');
            return $this->emptyTotal();
        }

        // Registration lookup: for each user_id, their most recent
        // non-cancelled MR on this match.
        $registrations = MatchRegistration::where('match_id', $match->id)
            ->where('registration_status', '!=', 'cancelled')
            ->whereIn('user_id', $scores->pluck('user_id')->filter()->unique()->all())
            ->orderByDesc('id')
            ->get(['user_id', 'division_id'])
            ->unique('user_id')      // most recent wins
            ->keyBy('user_id');

        $toFill = [];         // score_id => registration.division_id
        $toOverwrite = [];    // score_id => ['old' => x, 'new' => y]
        $mismatchesSkipped = [];
        $noRegistration = [];

        foreach ($scores as $score) {
            $reg = $registrations->get($score->user_id);

            if (! $reg || $reg->division_id === null) {
                if ($score->division_id === null) {
                    $noRegistration[] = $score;
                }
                continue;
            }

            if ($score->division_id === null) {
                $toFill[$score->id] = $reg->division_id;
                continue;
            }

            if ((int) $score->division_id !== (int) $reg->division_id) {
                if ($overwriteMismatches) {
                    $toOverwrite[$score->id] = [
                        'old' => (int) $score->division_id,
                        'new' => (int) $reg->division_id,
                        'user_id' => $score->user_id,
                        'shooter_name' => $score->shooter_name,
                    ];
                } else {
                    $mismatchesSkipped[] = [
                        'score_id' => $score->id,
                        'user_id' => $score->user_id,
                        'shooter_name' => $score->shooter_name,
                        'score_division_id' => (int) $score->division_id,
                        'registration_division_id' => (int) $reg->division_id,
                    ];
                }
            }
        }

        $this->reportMatchPlan($toFill, $toOverwrite, $mismatchesSkipped, $noRegistration);

        if ($dryRun) {
            return [
                'filled' => count($toFill),
                'overwritten_mismatch' => count($toOverwrite),
                'skipped_mismatch' => count($mismatchesSkipped),
                'skipped_no_registration' => count($noRegistration),
            ];
        }

        if ($toFill === [] && $toOverwrite === []) {
            $this->line('  Nothing to write.');
            return [
                'filled' => 0,
                'overwritten_mismatch' => 0,
                'skipped_mismatch' => count($mismatchesSkipped),
                'skipped_no_registration' => count($noRegistration),
            ];
        }

        DB::transaction(function () use ($toFill, $toOverwrite, $match, $mismatchesSkipped, $noRegistration, $overwriteMismatches): void {
            foreach ($toFill as $scoreId => $divisionId) {
                Score::where('id', $scoreId)->update(['division_id' => $divisionId]);
            }
            foreach ($toOverwrite as $scoreId => $delta) {
                Score::where('id', $scoreId)->update(['division_id' => $delta['new']]);
            }

            AuditLog::create([
                'user_id' => null,
                'actor_type' => 'system',
                'action_type' => 'score_division_backfill',
                'entity_type' => 'MatchEvent',
                'entity_id' => $match->id,
                'old_value' => null,
                'new_value' => [
                    'source' => 'registration',
                    'filled' => count($toFill),
                    'overwritten_mismatch' => count($toOverwrite),
                    'skipped_mismatch' => count($mismatchesSkipped),
                    'skipped_no_registration' => count($noRegistration),
                    'overwrite_mode' => $overwriteMismatches,
                    'overwritten_ids' => array_keys($toOverwrite),
                    'mismatch_details' => $mismatchesSkipped,
                ],
                'reason' => "Backfilled {$this->pluralised(count($toFill), 'Score row')} on match #{$match->id} from MatchRegistration"
                    .($toOverwrite === [] ? '' : "; overwrote {$this->pluralised(count($toOverwrite), 'mismatched row')}"),
            ]);
        });

        $this->info('  ✓ Wrote '.count($toFill).' fill(s), '.count($toOverwrite).' overwrite(s), audit-logged.');

        if (! $skipStandings) {
            $this->line('  Recalculating rankings + season standings...');
            $standings->recalculateForMatch($match->fresh());
        }

        return [
            'filled' => count($toFill),
            'overwritten_mismatch' => count($toOverwrite),
            'skipped_mismatch' => count($mismatchesSkipped),
            'skipped_no_registration' => count($noRegistration),
        ];
    }

    private function reportMatchPlan(
        array $toFill,
        array $toOverwrite,
        array $mismatchesSkipped,
        array $noRegistration,
    ): void {
        if ($toFill !== []) {
            $this->line('  Will fill '.count($toFill).' Score row(s) whose division_id is NULL.');
        }
        if ($toOverwrite !== []) {
            $this->warn('  Will OVERWRITE '.count($toOverwrite).' Score row(s) with a different division than their registration:');
            foreach ($toOverwrite as $delta) {
                $this->line("    ~ {$delta['shooter_name']} (score #{$delta['old']} → reg #{$delta['new']})");
            }
        }
        if ($mismatchesSkipped !== []) {
            $this->warn('  '.count($mismatchesSkipped).' Score row(s) disagree with their registration — kept as-is (pass --overwrite-mismatches to force):');
            foreach ($mismatchesSkipped as $m) {
                $this->line("    ? {$m['shooter_name']}: score division_id={$m['score_division_id']}  reg division_id={$m['registration_division_id']}");
            }
        }
        if ($noRegistration !== []) {
            $this->line('  '.count($noRegistration).' Score row(s) with NULL division_id have no MatchRegistration — skipped (walk-in flow will cover these later).');
        }
    }

    private function pluralised(int $n, string $singular): string
    {
        return $n.' '.$singular.($n === 1 ? '' : 's');
    }

    private function emptyTotal(): array
    {
        return [
            'filled' => 0,
            'overwritten_mismatch' => 0,
            'skipped_mismatch' => 0,
            'skipped_no_registration' => 0,
        ];
    }
}
