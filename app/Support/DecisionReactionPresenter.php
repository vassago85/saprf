<?php

namespace App\Support;

use App\Models\Decision;
use App\Models\User;
use App\Services\SettingsService;

/**
 * View-model for the public reaction block. Keeps tally math and the
 * visibility rules out of Blade.
 *
 * @phpstan-type ReactionView array{
 *   total: int,
 *   has_enough: bool,
 *   show_split: bool,
 *   for_percent: int|null,
 *   against_percent: int|null,
 *   neutral_percent: int|null,
 *   likes: int,
 *   dislikes: int,
 *   neutrals: int,
 *   window_open: bool,
 *   voting_closes_label: string|null,
 *   is_archived: bool,
 *   has_reacted: bool,
 *   can_react: bool,
 *   guest: bool,
 *   not_active_member: bool
 * }
 */
class DecisionReactionPresenter
{
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_AFTER_REACTION = 'after_reaction';

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function visibilityMode(): string
    {
        $mode = (string) $this->settings->get(
            SettingsService::DECISION_REACTION_VISIBILITY,
            self::VISIBILITY_PUBLIC,
        );

        return in_array($mode, [self::VISIBILITY_PUBLIC, self::VISIBILITY_AFTER_REACTION], true)
            ? $mode
            : self::VISIBILITY_PUBLIC;
    }

    /**
     * @return ReactionView
     */
    public function forDecision(Decision $decision, ?User $viewer): array
    {
        $tally = $decision->tally;
        $likes = (int) ($tally?->likes ?? 0);
        $dislikes = (int) ($tally?->dislikes ?? 0);
        $neutrals = (int) ($tally?->neutrals ?? 0);
        $total = $likes + $dislikes + $neutrals;
        $hasEnough = $total >= Decision::MIN_REACTIONS_FOR_RESULTS;
        $hasReacted = $viewer !== null && $decision->memberHasReacted($viewer->id);
        $guest = $viewer === null;
        $notActiveMember = $viewer !== null && ! $viewer->isActiveMember();
        $windowOpen = $decision->reactionsAreOpen();
        $isArchived = $decision->isArchived() || $decision->votingWindowHasClosed();

        // Once the window has closed, show the final split even below the
        // usual 10-reaction threshold so the archive is still useful.
        $showSplit = false;
        if ($hasEnough || $isArchived) {
            if ($hasEnough) {
                $mode = $this->visibilityMode();
                $showSplit = $mode === self::VISIBILITY_PUBLIC || $hasReacted || $isArchived;
            } elseif ($isArchived && $total > 0) {
                $showSplit = true;
            }
        }

        $percentages = $showSplit ? $decision->reactionPercentages() : null;

        return [
            'total' => $total,
            'has_enough' => $hasEnough,
            'show_split' => $showSplit,
            'for_percent' => $percentages['for'] ?? null,
            'against_percent' => $percentages['against'] ?? null,
            'neutral_percent' => $percentages['neutral'] ?? null,
            'likes' => $likes,
            'dislikes' => $dislikes,
            'neutrals' => $neutrals,
            'window_open' => $windowOpen,
            'voting_closes_label' => $decision->votingClosesLabel(),
            'is_archived' => $isArchived,
            'has_reacted' => $hasReacted,
            'can_react' => $windowOpen
                && $viewer !== null
                && $viewer->isActiveMember()
                && ! $hasReacted,
            'guest' => $guest,
            'not_active_member' => $notActiveMember,
        ];
    }
}
