<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Score;
use App\Models\User;
use App\Services\StandingsCalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
 *
 * Second-tier fallback (--fall-back-to-user-division)
 * ---------------------------------------------------
 * Scraped-import matches (precisionrifle.co.za events) typically have no
 * MatchRegistration rows on our side — shooters registered on the source
 * site, not here — so the registration fallback can't help them. When the
 * flag is set, any score with NULL division_id that didn't get a
 * registration-sourced fill is backfilled from the shooter's User.division_id
 * (their registered home division). The flag is opt-in because it attributes
 * the score to the shooter's default division without per-match evidence —
 * fine for the overwhelming "Dalton de Bruin always shoots Open" case,
 * potentially wrong for a shooter who switched divisions for a day. The
 * audit log records which source filled each row so the move is reversible.
 * --overwrite-mismatches is deliberately NOT honoured for the user-default
 * source: it only ever fills nulls, never clobbers an existing non-null.
 */
class BackfillScoreDivisionFromRegistrationCommand extends Command
{
    protected $signature = 'scores:backfill-division-from-registration
        {match? : Optional MatchEvent ID; without it the command scans every match with at least one score-with-null-division}
        {--dry-run : Report what would change without writing}
        {--overwrite-mismatches : Also overwrite scores whose division disagrees with the registration (default: warn and skip)}
        {--fall-back-to-user-division : When a score has NULL division_id and no MatchRegistration (typical of scraped imports), fill from the shooter\'s User.division_id}
        {--skip-standings : Skip StandingsCalculationService::recalculateForMatch after writing}';

    protected $description = 'Backfill Score.division_id from each shooter\'s MatchRegistration for the same match';

    public function handle(StandingsCalculationService $standings): int
    {
        $matchIdArg = $this->argument('match');
        $dryRun = (bool) $this->option('dry-run');
        $overwriteMismatches = (bool) $this->option('overwrite-mismatches');
        $fallBackToUserDivision = (bool) $this->option('fall-back-to-user-division');
        $skipStandings = (bool) $this->option('skip-standings');

        $matches = $this->resolveMatches($matchIdArg);
        if ($matches->isEmpty()) {
            $this->info('No matches with missing Score.division_id — nothing to do.');
            return self::SUCCESS;
        }

        $this->info('=== Backfill Score.division_id from MatchRegistration ===');
        $this->line('Mode:              '.($dryRun ? 'DRY RUN (no writes)' : 'LIVE'));
        $this->line('Mismatch handling: '.($overwriteMismatches ? 'OVERWRITE existing division_id' : 'WARN and skip (keep existing)'));
        $this->line('User fallback:     '.($fallBackToUserDivision ? 'ENABLED (User.division_id fills where no MatchRegistration)' : 'off'));
        $this->line('Matches to scan:   '.$matches->count());
        $this->newLine();

        $grandTotal = [
            'filled' => 0,
            'filled_from_user_default' => 0,
            'skipped_no_registration' => 0,
            'skipped_mismatch' => 0,
            'overwritten_mismatch' => 0,
        ];

        foreach ($matches as $match) {
            $matchTotal = $this->processMatch(
                $match,
                $dryRun,
                $overwriteMismatches,
                $fallBackToUserDivision,
                $skipStandings,
                $standings,
            );

            foreach ($matchTotal as $key => $value) {
                $grandTotal[$key] += $value;
            }
        }

        $this->newLine();
        $this->info('=== Overall totals ===');
        $this->info("Filled (registration): {$grandTotal['filled']}   Filled (user default): {$grandTotal['filled_from_user_default']}   Overwritten: {$grandTotal['overwritten_mismatch']}   Skipped-no-registration: {$grandTotal['skipped_no_registration']}   Skipped-mismatch: {$grandTotal['skipped_mismatch']}");

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
     * @return array{filled:int,filled_from_user_default:int,skipped_no_registration:int,skipped_mismatch:int,overwritten_mismatch:int}
     */
    private function processMatch(
        MatchEvent $match,
        bool $dryRun,
        bool $overwriteMismatches,
        bool $fallBackToUserDivision,
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

        $userIds = $scores->pluck('user_id')->filter()->unique()->all();

        // Registration lookup: for each user_id, their most recent
        // non-cancelled MR on this match.
        $registrations = MatchRegistration::where('match_id', $match->id)
            ->where('registration_status', '!=', 'cancelled')
            ->whereIn('user_id', $userIds)
            ->orderByDesc('id')
            ->get(['user_id', 'division_id'])
            ->unique('user_id')      // most recent wins
            ->keyBy('user_id');

        // User-default division lookup. Only loaded when the flag is on so
        // the default behaviour matches historic (registration-only) runs.
        $userDefaults = $fallBackToUserDivision
            ? User::query()
                ->whereIn('id', $userIds)
                ->whereNotNull('division_id')
                ->pluck('division_id', 'id')
            : collect();

        $toFill = [];                 // score_id => ['division_id' => x, 'source' => 'registration'|'user_default']
        $toOverwrite = [];            // score_id => ['old' => x, 'new' => y]
        $mismatchesSkipped = [];
        $noRegistration = [];

        foreach ($scores as $score) {
            $reg = $registrations->get($score->user_id);

            if (! $reg || $reg->division_id === null) {
                if ($score->division_id === null) {
                    $userDefault = $userDefaults->get($score->user_id);
                    if ($userDefault !== null) {
                        $toFill[$score->id] = [
                            'division_id' => (int) $userDefault,
                            'source' => 'user_default',
                        ];
                    } else {
                        $noRegistration[] = $score;
                    }
                }
                continue;
            }

            if ($score->division_id === null) {
                $toFill[$score->id] = [
                    'division_id' => (int) $reg->division_id,
                    'source' => 'registration',
                ];
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

        $filledFromRegistration = collect($toFill)->where('source', 'registration')->count();
        $filledFromUserDefault = collect($toFill)->where('source', 'user_default')->count();

        $this->reportMatchPlan($toFill, $toOverwrite, $mismatchesSkipped, $noRegistration);

        if ($dryRun) {
            return [
                'filled' => $filledFromRegistration,
                'filled_from_user_default' => $filledFromUserDefault,
                'overwritten_mismatch' => count($toOverwrite),
                'skipped_mismatch' => count($mismatchesSkipped),
                'skipped_no_registration' => count($noRegistration),
            ];
        }

        if ($toFill === [] && $toOverwrite === []) {
            $this->line('  Nothing to write.');
            return [
                'filled' => 0,
                'filled_from_user_default' => 0,
                'overwritten_mismatch' => 0,
                'skipped_mismatch' => count($mismatchesSkipped),
                'skipped_no_registration' => count($noRegistration),
            ];
        }

        DB::transaction(function () use ($toFill, $toOverwrite, $match, $mismatchesSkipped, $noRegistration, $overwriteMismatches, $filledFromRegistration, $filledFromUserDefault): void {
            foreach ($toFill as $scoreId => $delta) {
                Score::where('id', $scoreId)->update(['division_id' => $delta['division_id']]);
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
                    'source' => $filledFromUserDefault > 0 ? 'registration_and_user_default' : 'registration',
                    'filled' => $filledFromRegistration,
                    'filled_from_user_default' => $filledFromUserDefault,
                    'overwritten_mismatch' => count($toOverwrite),
                    'skipped_mismatch' => count($mismatchesSkipped),
                    'skipped_no_registration' => count($noRegistration),
                    'overwrite_mode' => $overwriteMismatches,
                    'overwritten_ids' => array_keys($toOverwrite),
                    'mismatch_details' => $mismatchesSkipped,
                ],
                'reason' => "Backfilled {$this->pluralised($filledFromRegistration, 'Score row')} on match #{$match->id} from MatchRegistration"
                    .($filledFromUserDefault === 0 ? '' : "; {$this->pluralised($filledFromUserDefault, 'additional row')} from User.division_id")
                    .($toOverwrite === [] ? '' : "; overwrote {$this->pluralised(count($toOverwrite), 'mismatched row')}"),
            ]);
        });

        $this->info('  ✓ Wrote '.$filledFromRegistration.' reg-fill(s)'
            .($filledFromUserDefault > 0 ? ', '.$filledFromUserDefault.' user-default fill(s)' : '')
            .', '.count($toOverwrite).' overwrite(s), audit-logged.');

        if (! $skipStandings) {
            $this->line('  Recalculating rankings + season standings...');
            $standings->recalculateForMatch($match->fresh());
        }

        return [
            'filled' => $filledFromRegistration,
            'filled_from_user_default' => $filledFromUserDefault,
            'overwritten_mismatch' => count($toOverwrite),
            'skipped_mismatch' => count($mismatchesSkipped),
            'skipped_no_registration' => count($noRegistration),
        ];
    }

    /**
     * @param  array<int,array{division_id:int,source:string}>  $toFill
     * @param  array<int,array<string,mixed>>  $toOverwrite
     * @param  array<int,array<string,mixed>>  $mismatchesSkipped
     * @param  array<int,Score>  $noRegistration
     */
    private function reportMatchPlan(
        array $toFill,
        array $toOverwrite,
        array $mismatchesSkipped,
        array $noRegistration,
    ): void {
        $fromReg = collect($toFill)->where('source', 'registration')->count();
        $fromUser = collect($toFill)->where('source', 'user_default')->count();

        if ($fromReg > 0) {
            $this->line('  Will fill '.$fromReg.' Score row(s) whose division_id is NULL from MatchRegistration.');
        }
        if ($fromUser > 0) {
            $this->line('  Will fill '.$fromUser.' additional Score row(s) from User.division_id (no MatchRegistration on file).');
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
            $this->line('  '.count($noRegistration).' Score row(s) with NULL division_id have no MatchRegistration and no User.division_id — skipped (pass --fall-back-to-user-division to use the shooter\'s default division when available).');
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
            'filled_from_user_default' => 0,
            'overwritten_mismatch' => 0,
            'skipped_mismatch' => 0,
            'skipped_no_registration' => 0,
        ];
    }
}
