<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\Payout;
use App\Models\Score;
use Illuminate\Support\Collection;

/**
 * Derives the match close-out wizard state from the match itself.
 *
 * The wizard is a five-step close-out flow living on the Scores workspace
 * tab:
 *
 *   1. Import scores
 *   2. Correct names and allocations (link assign-later rows, set divisions,
 *      resolve zero-score ambiguity)
 *   3. Agree walk-in fees (MD ticks the box that the platform fee and the
 *      SAPRF levy come off their payout for every walk-in on this match)
 *   4. Mark scores complete (fires shooter notifications once). The MD
 *      may request the payout from this step before marking complete.
 *   5. Request MD payout
 *
 * There is deliberately no "current step" column on the match. The step is
 * derived every time the view loads so a refresh, a return from Finances,
 * or an edit that un-does an earlier step always lands on the first
 * unfinished one.
 */
class MatchCloseoutWizard
{
    public const STEP_IMPORT = 'import';

    public const STEP_NAMES = 'names';

    public const STEP_WALKINS = 'walkins';

    public const STEP_COMPLETE = 'complete';

    public const STEP_PAYOUT = 'payout';

    public const STEP_DONE = 'done';

    /**
     * Snapshot of every gate the Scores view needs: current step, per-step
     * done flags, and the raw counts that drive the "N shooters still need
     * a division" chips.
     *
     * @return array{
     *     current: string,
     *     steps: list<array{key: string, label: string, done: bool, current: bool}>,
     *     pending_link_count: int,
     *     missing_division_count: int,
     *     unconfirmed_zero_count: int,
     *     has_walkins: bool,
     *     walkin_acknowledged: bool,
     *     scores_finalized: bool,
     *     payout: ?Payout,
     *     has_scores: bool,
     * }
     */
    public function state(MatchEvent $match): array
    {
        $hasScores = $match->scores()->exists();
        $pendingLinkCount = $this->pendingLinkScores($match)->count();
        $missingDivisionCount = $this->missingDivisionCount($match);
        $unconfirmedZeroCount = $this->unconfirmedZeroCount($match);
        $walkIns = $match->walkInRegistrations();
        $hasWalkIns = $walkIns->isNotEmpty();
        $walkinAcknowledged = $match->walkInFeesAreAcknowledged();
        $scoresFinalized = $match->scoresAreFinalized();
        $payout = $match->payouts()
            ->where('payee_type', 'match_director')
            ->latest('id')
            ->first();

        $namesReady = $hasScores
            && $pendingLinkCount === 0
            && $missingDivisionCount === 0
            && $unconfirmedZeroCount === 0;
        $walkinsReady = $namesReady && $walkinAcknowledged;
        $completeReady = $walkinsReady && $scoresFinalized;
        $payoutReady = $completeReady && $payout !== null;

        $current = match (true) {
            ! $hasScores => self::STEP_IMPORT,
            ! $namesReady => self::STEP_NAMES,
            ! $walkinsReady => self::STEP_WALKINS,
            ! $completeReady => self::STEP_COMPLETE,
            ! $payoutReady => self::STEP_PAYOUT,
            default => self::STEP_DONE,
        };

        return [
            'current' => $current,
            'steps' => [
                [
                    'key' => self::STEP_IMPORT,
                    'label' => 'Import',
                    'done' => $hasScores,
                    'current' => $current === self::STEP_IMPORT,
                ],
                [
                    'key' => self::STEP_NAMES,
                    'label' => 'Names & allocations',
                    'done' => $namesReady,
                    'current' => $current === self::STEP_NAMES,
                ],
                [
                    'key' => self::STEP_WALKINS,
                    'label' => 'Walk-in fees',
                    'done' => $walkinsReady,
                    'current' => $current === self::STEP_WALKINS,
                ],
                [
                    'key' => self::STEP_COMPLETE,
                    'label' => 'Complete scores',
                    'done' => $completeReady,
                    'current' => $current === self::STEP_COMPLETE,
                ],
                [
                    'key' => self::STEP_PAYOUT,
                    'label' => 'Request payout',
                    'done' => $payoutReady,
                    'current' => $current === self::STEP_PAYOUT,
                ],
            ],
            'pending_link_count' => $pendingLinkCount,
            'missing_division_count' => $missingDivisionCount,
            'unconfirmed_zero_count' => $unconfirmedZeroCount,
            'has_walkins' => $hasWalkIns,
            'walkin_acknowledged' => $walkinAcknowledged,
            'scores_finalized' => $scoresFinalized,
            'payout' => $payout,
            'has_scores' => $hasScores,
        ];
    }

    /**
     * Scores that still carry the Impact "assign later" flag — a stub user
     * was created at import time, no real member has been linked yet, and
     * the row therefore does not count for the season.
     *
     * @return Collection<int, Score>
     */
    public function pendingLinkScores(MatchEvent $match): Collection
    {
        return Score::query()
            ->where('match_id', $match->id)
            ->with(['user:id,name', 'division:id,name'])
            ->orderBy('shooter_name')
            ->get()
            ->filter(fn (Score $s) => ! empty(($s->raw_meta['impact']['needs_member_link'] ?? false)))
            ->values();
    }

    /**
     * Scores on the match without any division. The CSV importer resolves
     * `division` columns to a division_id when it can, so an empty value
     * means neither the entry nor the file agreed on a division and the
     * MD has to pick one before the wizard moves on.
     *
     * @return Collection<int, Score>
     */
    public function missingDivisionScores(MatchEvent $match): Collection
    {
        return Score::query()
            ->where('match_id', $match->id)
            ->whereNull('division_id')
            ->with('user:id,name')
            ->orderBy('shooter_name')
            ->get();
    }

    /**
     * Zero-score rows that have not had the "did they actually turn up"
     * check applied — surfaced on the names step so the MD confirms or
     * removes them before completing the match.
     *
     * @return Collection<int, Score>
     */
    public function unconfirmedZeroScores(MatchEvent $match): Collection
    {
        return Score::query()
            ->where('match_id', $match->id)
            ->where('raw_score', 0)
            ->whereNull('participation_confirmed_at')
            ->with(['user:id,name', 'division:id,name'])
            ->orderBy('shooter_name')
            ->get();
    }

    private function missingDivisionCount(MatchEvent $match): int
    {
        return (int) Score::query()
            ->where('match_id', $match->id)
            ->whereNull('division_id')
            ->count();
    }

    private function unconfirmedZeroCount(MatchEvent $match): int
    {
        return (int) Score::query()
            ->where('match_id', $match->id)
            ->where('raw_score', 0)
            ->whereNull('participation_confirmed_at')
            ->count();
    }
}
