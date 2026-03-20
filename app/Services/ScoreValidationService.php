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

        if ($isValid) {
            $score->status = 'valid';
            $score->is_member = true;
            $score->validation_reason = 'Valid paid member.';
        } else {
            $withinGraceWindow = now()->lte($matchDate->copy()->addDays(7));
            $score->is_member = false;

            if ($withinGraceWindow) {
                $score->status = 'pending';
                $score->validation_reason = 'Within 7-day regularisation window.';
            } else {
                $score->status = 'invalid';
                $score->validation_reason = 'Membership not valid on match date.';
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
