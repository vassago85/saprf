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
use App\Services\Announcements\AnnouncementPublisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * On first publish, fan a Decision Register notice out through the normal
 * announcement pipeline (Active members → mail + in-app + push).
 *
 * Idempotent: a decision keeps one announcement_id forever so edit /
 * re-save never double-emails. Batch publish shares one announcement
 * across many decisions.
 */
class DecisionPublishNotifier
{
    public function __construct(
        private readonly AnnouncementPublisher $publisher,
    ) {}

    public function notifyPublished(Decision $decision): Announcement
    {
        return $this->notifyPublishedBatch(collect([$decision]));
    }

    /**
     * Publish-notify one or more decisions in a single digest announcement.
     * Decisions that already have an announcement_id are skipped. Caller
     * must already have set status/published_at (or pass drafts that this
     * method will publish when $publishDrafts is true).
     *
     * @param  Collection<int, Decision>  $decisions
     */
    public function notifyPublishedBatch(Collection $decisions, bool $publishDrafts = false): Announcement
    {
        if ($decisions->isEmpty()) {
            throw new RuntimeException('No decisions to notify.');
        }

        return DB::transaction(function () use ($decisions, $publishDrafts): Announcement {
            $ids = $decisions->pluck('id')->unique()->values()->all();

            /** @var Collection<int, Decision> $locked */
            $locked = Decision::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $eligible = $locked->filter(function (Decision $decision) use ($publishDrafts): bool {
                if ($decision->announcement_id) {
                    return false;
                }

                if ($publishDrafts && $decision->isDraft()) {
                    return true;
                }

                return $decision->isPublished();
            })->values();

            if ($eligible->isEmpty()) {
                $existingId = $locked->first(fn (Decision $d) => $d->announcement_id)?->announcement_id;
                if ($existingId) {
                    return Announcement::query()->findOrFail($existingId);
                }

                throw new RuntimeException('No eligible decisions to notify.');
            }

            $publishedAt = now();
            if ($publishDrafts) {
                foreach ($eligible as $decision) {
                    if ($decision->isDraft()) {
                        $decision->forceFill([
                            'status' => DecisionStatus::Published,
                            'published_at' => $publishedAt,
                        ])->save();
                    }
                }
            }

            foreach ($eligible as $decision) {
                if (! $decision->isPublished()) {
                    throw new RuntimeException('Only published decisions can be emailed to members.');
                }
            }

            $createdBy = (int) $eligible->first()->created_by;
            $count = $eligible->count();

            $announcement = Announcement::create([
                'title' => $count === 1
                    ? 'Decision Register: '.$eligible->first()->title
                    : 'Decision Register: '.$count.' new decisions',
                'body' => $count === 1
                    ? $this->bodySingle($eligible->first())
                    : $this->bodyBatch($eligible),
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

            $announcement->audiences()->create([
                'type' => AudienceType::ActiveMembers,
                'mode' => AudienceMode::Include,
                'value' => [],
            ]);

            foreach ($eligible as $decision) {
                $decision->forceFill(['announcement_id' => $announcement->id])->save();
            }

            return $this->publisher->sendNow($announcement);
        });
    }

    private function bodySingle(Decision $decision): string
    {
        $lines = [
            'A new decision has been published on the SAPRF Decision Register.',
            '',
            '**'.$decision->title.'**',
            '',
            $decision->body,
        ];

        if ($decision->reference) {
            $lines[] = '';
            $lines[] = '_'.$decision->reference.'_';
        }

        $lines[] = '';
        $lines[] = $this->footerBlurb();
        $lines[] = '';
        $lines[] = route('decisions.index').'#decision-'.$decision->id;

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, Decision>  $decisions
     */
    private function bodyBatch(Collection $decisions): string
    {
        $count = $decisions->count();
        $lines = [
            $count.' new decisions have been published on the SAPRF Decision Register.',
            '',
            'Open each item on the register to read the full decision and cast your anonymous vote:',
            '',
        ];

        $n = 1;
        foreach ($decisions as $decision) {
            $label = $decision->title;
            if ($decision->reference) {
                $label .= ' ('.$decision->reference.')';
            }
            $lines[] = $n.'. **'.$label.'**';
            $lines[] = '   '.route('decisions.index').'#decision-'.$decision->id;
            $lines[] = '';
            $n++;
        }

        $lines[] = $this->footerBlurb();
        $lines[] = '';
        $lines[] = route('decisions.index');

        return implode("\n", $lines);
    }

    private function footerBlurb(): string
    {
        return 'Only active paid-up members may vote (👍 For, 👎 Against, or 🤷 Neutral). Voting does not immediately change the decision; it gives ExCo an idea of whether they need to relook at it. The system only records that you voted — not which option you chose — so you can vote only once. Be careful how you use your vote.';
    }
}
