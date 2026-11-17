<?php

namespace App\Services;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementRetention;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Enums\DeliveryChannel;
use App\Enums\DecisionStatus;
use App\Models\Announcement;
use App\Models\Decision;
use App\Models\DecisionMember;
use App\Models\User;
use App\Services\Announcements\AnnouncementPublisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * When voting windows close, email ExCo plus everyone who voted — one
 * digest per publish batch (shared announcement_id), titles + tallies only.
 *
 * Idempotent via results_announcement_id on each decision.
 */
class DecisionResultsNotifier
{
    public function __construct(
        private readonly AnnouncementPublisher $publisher,
    ) {}

    /**
     * Send pending close digests for archived decisions that have not
     * been results-notified yet. Returns how many digests were sent.
     */
    public function sendPending(): int
    {
        $pending = Decision::query()
            ->where('status', DecisionStatus::Archived)
            ->whereNull('results_announcement_id')
            ->whereNotNull('published_at')
            ->with('tally')
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $groups = $pending->groupBy(
            fn (Decision $decision) => $decision->announcement_id
                ? 'batch-'.$decision->announcement_id
                : 'solo-'.$decision->id,
        );

        $sent = 0;
        foreach ($groups as $group) {
            $this->notifyBatch($group->values());
            $sent++;
        }

        return $sent;
    }

    /**
     * @param  Collection<int, Decision>  $decisions
     */
    public function notifyBatch(Collection $decisions): Announcement
    {
        if ($decisions->isEmpty()) {
            throw new \InvalidArgumentException('No decisions to notify.');
        }

        return DB::transaction(function () use ($decisions): Announcement {
            $ids = $decisions->pluck('id')->all();

            /** @var Collection<int, Decision> $locked */
            $locked = Decision::query()
                ->whereIn('id', $ids)
                ->with('tally')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $eligible = $locked
                ->filter(fn (Decision $d) => $d->isArchived() && $d->results_announcement_id === null)
                ->values();

            if ($eligible->isEmpty()) {
                $existingId = $locked->first(fn (Decision $d) => $d->results_announcement_id)?->results_announcement_id;

                return Announcement::query()->findOrFail($existingId);
            }

            $voterIds = DecisionMember::query()
                ->whereIn('decision_id', $eligible->pluck('id'))
                ->distinct()
                ->pluck('member_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $createdBy = (int) (
                $eligible->first()->created_by
                ?? User::role('chair')->orderBy('id')->value('id')
                ?? User::role('exco')->orderBy('id')->value('id')
            );

            $count = $eligible->count();
            $announcement = Announcement::create([
                'title' => $count === 1
                    ? 'Decision Register results: '.$eligible->first()->title
                    : 'Decision Register results: '.$count.' decisions',
                'body' => $this->body($eligible),
                'category' => AnnouncementCategory::AgmGovernance,
                'retention' => AnnouncementRetention::Permanent,
                'priority' => AnnouncementPriority::Normal,
                'requires_acknowledgement' => false,
                'deliver_via' => [
                    DeliveryChannel::Database->value,
                    DeliveryChannel::Mail->value,
                    DeliveryChannel::WebPush->value,
                ],
                'status' => AnnouncementStatus::Draft,
                'created_by' => $createdBy,
            ]);

            // ExCo (and Chair — chair may not always carry the exco role).
            $announcement->audiences()->create([
                'type' => AudienceType::Role,
                'mode' => AudienceMode::Include,
                'value' => ['role' => 'exco'],
            ]);
            $announcement->audiences()->create([
                'type' => AudienceType::Role,
                'mode' => AudienceMode::Include,
                'value' => ['role' => 'chair'],
            ]);

            if ($voterIds !== []) {
                $announcement->audiences()->create([
                    'type' => AudienceType::Individual,
                    'mode' => AudienceMode::Include,
                    'value' => ['user_ids' => $voterIds],
                ]);
            }

            foreach ($eligible as $decision) {
                $decision->forceFill(['results_announcement_id' => $announcement->id])->save();
            }

            return $this->publisher->sendNow($announcement);
        });
    }

    /**
     * @param  Collection<int, Decision>  $decisions
     */
    private function body(Collection $decisions): string
    {
        $count = $decisions->count();
        $lines = [
            $count === 1
                ? 'Voting has closed on this Decision Register item. Final tally:'
                : 'Voting has closed on '.$count.' Decision Register items. Final tallies:',
            '',
        ];

        $n = 1;
        foreach ($decisions as $decision) {
            $lines[] = $n.'. **'.$decision->title.'**';
            if ($decision->reference) {
                $lines[] = '   _'.$decision->reference.'_';
            }
            $lines[] = '   '.$this->tallyLine($decision);
            $lines[] = '   '.route('decisions.index').'#decision-'.$decision->id;
            $lines[] = '';
            $n++;
        }

        $lines[] = 'These results are also on the Decision Register. Reactions stay anonymous — totals only.';
        $lines[] = '';
        $lines[] = route('decisions.index');

        return implode("\n", $lines);
    }

    private function tallyLine(Decision $decision): string
    {
        $total = $decision->totalReactions();
        if ($total === 0) {
            return '0 reactions';
        }

        $pct = $decision->reactionPercentages();
        $likes = (int) $decision->tally->likes;
        $dislikes = (int) $decision->tally->dislikes;
        $neutrals = (int) $decision->tally->neutrals;

        return $total.' reactions · '
            .$likes.' for ('.$pct['for'].'%) · '
            .$neutrals.' neutral ('.$pct['neutral'].'%) · '
            .$dislikes.' against ('.$pct['against'].'%)';
    }
}
