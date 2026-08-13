<?php

namespace App\Services;

use App\Models\Score;
use App\Models\ShooterLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ScoreValidationService
{
    public function __construct(
        private readonly MembershipValidationService $membershipValidationService,
        private readonly StandingsCalculationService $standingsCalculationService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Classify a score's eligibility for the season log based on the
     * shooter's membership state at the time of the match.
     *
     * Status vocabulary:
     *   valid       — active + paid member on match date. Counts for season, shown in match.
     *   pending     — HAD a membership record but not valid on match date, still
     *                 within 7-day grace window. Shown in match; will retro-flip
     *                 to 'valid' if they renew before grace expires.
     *   lapsed      — HAD a membership record, past 7-day grace, still not valid.
     *                 Shown in match, does NOT count for season.
     *   non_member  — NO membership record at all. Shown in match, no grace
     *                 (they need to join, not renew), does NOT count for season.
     *   invalid     — No user linked at all (data-quality garbage). Hidden.
     *
     * Applies uniformly to PRS and PR22.
     */
    public function evaluateScoreStatus(Score $score): Score
    {
        $old = $score->only(['status', 'is_member', 'validation_reason']);
        $matchDate = Carbon::parse($score->match_date);

        if (! $score->user_id) {
            $score->status = 'invalid';
            $score->is_member = false;
            $score->validation_reason = 'No linked member account.';
            $score->save();
            $this->syncOfficialLog($score);

            return $score;
        }

        $user = User::query()->with('membership')->find($score->user_id);
        $isValid = $this->membershipValidationService->isUserValidForOfficialPurposes($user, $matchDate);
        $isFreeRegistrant = ($user?->membership?->membership_type ?? null) === 'free';

        if ($isValid) {
            $score->status = 'valid';
            $score->is_member = true;
            $score->validation_reason = 'Valid paid member.';
        } elseif (! $user?->membership || $isFreeRegistrant) {
            // No membership record, or a "free" registration (someone who was
            // forced to register to shoot a single provincial). Either way they
            // shot as a non-member: visible in the match, excluded from the log.
            $score->status = 'non_member';
            $score->is_member = false;
            $score->validation_reason = 'Shot as a non-member — score visible in match, excluded from season log.';
        } else {
            $withinGraceWindow = now()->lte($matchDate->copy()->addDays(7));
            $score->is_member = false;

            if ($withinGraceWindow) {
                $score->status = 'pending';
                $score->validation_reason = 'Lapsed on match date — within 7-day renewal grace window.';
            } else {
                $score->status = 'lapsed';
                $score->validation_reason = 'Lapsed on match date — grace window expired without renewal.';
            }
        }

        $score->save();
        $this->syncOfficialLog($score);

        $this->auditLogService->log(
            auth()->user(),
            'score.status.evaluated',
            'Score',
            $score->id,
            $old,
            $score->only(['status', 'is_member', 'validation_reason'])
        );

        return $score;
    }

    /**
     * Statuses whose scores should be visible in match results tables
     * (everything except orphans). Season standings still filter by 'valid'.
     */
    public const VISIBLE_STATUSES = ['valid', 'pending', 'lapsed', 'non_member'];

    /**
     * Re-evaluate all pending scores. Called daily by ResolvePendingScoresJob
     * to expire lapsed shooters out of the grace window, and on-demand by the
     * MembershipObserver when a payment lands so retroactive promotion to
     * 'valid' happens immediately.
     */
    public function resolvePendingScores(): int
    {
        $count = 0;

        Score::query()
            ->where('status', 'pending')
            ->with(['user.membership', 'match'])
            ->chunkById(100, function ($scores) use (&$count) {
                foreach ($scores as $score) {
                    $this->evaluateScoreStatus($score);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Resolve just one user's pending scores (used when a specific membership
     * gets renewed/paid — no need to sweep the whole table). Returns the list
     * of affected MatchEvent IDs so the caller can recalc standings for them.
     *
     * @return array<int, int>
     */
    public function resolvePendingScoresForUser(int $userId): array
    {
        $affectedMatchIds = [];

        Score::query()
            ->where('status', 'pending')
            ->where('user_id', $userId)
            ->with(['user.membership', 'match'])
            ->chunkById(100, function ($scores) use (&$affectedMatchIds) {
                foreach ($scores as $score) {
                    $before = $score->status;
                    $this->evaluateScoreStatus($score);
                    if ($score->status !== $before && $score->match_id) {
                        $affectedMatchIds[$score->match_id] = $score->match_id;
                    }
                }
            });

        return array_values($affectedMatchIds);
    }

    /**
     * Re-evaluate EVERY score for one shooter, regardless of its current status
     * (valid / pending / lapsed / non_member). Used when a membership is created
     * or edited in a way that could change the shooter's historical validity —
     * e.g. an admin backdates the start_date or extends the expiry_date to cover
     * matches the shooter previously shot as a non-member. Unlike
     * resolvePendingScoresForUser() (which only touches 'pending'), this promotes
     * 'non_member' / 'lapsed' scores to 'valid' too when the corrected window now
     * covers the match date. Returns the affected MatchEvent IDs so the caller can
     * rebuild those standings.
     *
     * @return array<int, int>
     */
    public function reevaluateScoresForUser(int $userId): array
    {
        $affectedMatchIds = [];

        Score::query()
            ->where('user_id', $userId)
            ->with(['user.membership', 'match'])
            ->chunkById(200, function ($scores) use (&$affectedMatchIds) {
                foreach ($scores as $score) {
                    $before = $score->status;
                    $this->evaluateScoreStatus($score);
                    if ($score->status !== $before && $score->match_id) {
                        $affectedMatchIds[$score->match_id] = $score->match_id;
                    }
                }
            });

        return array_values($affectedMatchIds);
    }

    public function overrideScoreStatus(Score $score, string $newStatus, string $reason, User $admin): Score
    {
        return DB::transaction(function () use ($score, $newStatus, $reason, $admin) {
            $old = $score->only(['status', 'validation_reason']);

            $score->status = $newStatus;
            $score->validation_reason = $reason;
            $score->is_member = $newStatus === 'valid';
            $score->save();

            $this->syncOfficialLog($score);

            $this->auditLogService->log(
                $admin,
                'score.status.overridden',
                'Score',
                $score->id,
                $old,
                $score->only(['status', 'validation_reason']),
                $reason
            );

            $this->standingsCalculationService->recalculateForMatch($score->match);

            return $score;
        });
    }

    public function syncOfficialLog(Score $score): void
    {
        if ($score->status === 'valid' && $score->user_id) {
            ShooterLog::query()->updateOrCreate(
                ['score_id' => $score->id],
                [
                    'user_id' => $score->user_id,
                    'match_id' => $score->match_id,
                    'counted' => true,
                    'notes' => 'Projected from valid score status.',
                ]
            );

            return;
        }

        if ($score->user_id) {
            ShooterLog::query()->updateOrCreate(
                ['score_id' => $score->id],
                [
                    'user_id' => $score->user_id,
                    'match_id' => $score->match_id,
                    'counted' => false,
                    'notes' => 'Score is not currently valid for official counting.',
                ]
            );
        }
    }
}
