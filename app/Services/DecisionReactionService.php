<?php

namespace App\Services;

use App\Models\Decision;
use App\Models\DecisionMember;
use App\Models\DecisionTally;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Anonymous one-time reactions. The like/dislike/neutral choice exists
 * only for the duration of the request — it is never written next to a
 * member id.
 */
class DecisionReactionService
{
    public const REACTION_LIKE = 'like';

    public const REACTION_DISLIKE = 'dislike';

    public const REACTION_NEUTRAL = 'neutral';

    /**
     * @return bool True when a new reaction was recorded; false when the
     *              member had already reacted (idempotent no-op).
     */
    public function react(Decision $decision, User $member, string $reaction): bool
    {
        if (! in_array($reaction, [
            self::REACTION_LIKE,
            self::REACTION_DISLIKE,
            self::REACTION_NEUTRAL,
        ], true)) {
            throw new InvalidArgumentException('Reaction must be like, dislike, or neutral.');
        }

        if (! $decision->reactionsAreOpen()) {
            throw new RuntimeException('The feedback window for this decision is closed.');
        }

        if (! $member->isActiveMember()) {
            throw new RuntimeException('Only active paid-up members may vote.');
        }

        return DB::transaction(function () use ($decision, $member, $reaction): bool {
            $tally = DecisionTally::query()
                ->where('decision_id', $decision->id)
                ->lockForUpdate()
                ->first();

            if ($tally === null) {
                throw new RuntimeException('Decision tally is missing.');
            }

            $inserted = DecisionMember::query()->insertOrIgnore([
                'decision_id' => $decision->id,
                'member_id' => $member->id,
            ]);

            if ($inserted === 0) {
                return false;
            }

            match ($reaction) {
                self::REACTION_LIKE => $tally->increment('likes'),
                self::REACTION_DISLIKE => $tally->increment('dislikes'),
                self::REACTION_NEUTRAL => $tally->increment('neutrals'),
            };

            return true;
        });
    }
}
