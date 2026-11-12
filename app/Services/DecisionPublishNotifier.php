<?php

namespace App\Services;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementRetention;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Enums\DeliveryChannel;
use App\Models\Announcement;
use App\Models\Decision;
use App\Services\Announcements\AnnouncementPublisher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * On first publish, fan a Decision Register notice out through the normal
 * announcement pipeline (Active members → mail + in-app + push).
 *
 * Idempotent: a decision keeps one announcement_id forever so edit /
 * re-save never double-emails.
 */
class DecisionPublishNotifier
{
    public function __construct(
        private readonly AnnouncementPublisher $publisher,
    ) {}

    public function notifyPublished(Decision $decision): Announcement
    {
        return DB::transaction(function () use ($decision): Announcement {
            /** @var Decision $locked */
            $locked = Decision::query()
                ->whereKey($decision->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->announcement_id) {
                return Announcement::query()->findOrFail($locked->announcement_id);
            }

            if (! $locked->isPublished()) {
                throw new RuntimeException('Only published decisions can be emailed to members.');
            }

            $announcement = Announcement::create([
                'title' => 'Decision Register: '.$locked->title,
                'body' => $this->body($locked),
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
                'created_by' => $locked->created_by,
            ]);

            $announcement->audiences()->create([
                'type' => AudienceType::ActiveMembers,
                'mode' => AudienceMode::Include,
                'value' => [],
            ]);

            $locked->forceFill(['announcement_id' => $announcement->id])->save();

            return $this->publisher->sendNow($announcement);
        });
    }

    private function body(Decision $decision): string
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
        $lines[] = 'Only active paid-up members may vote (👍 For, 👎 Against, or 🤷 Neutral). Voting does not immediately change the decision; it gives ExCo an idea of whether they need to relook at it. To protect anonymity you may vote only once: the system does not record which option you chose against your name — only that you voted, so you cannot vote twice. Be careful how you use your vote.';
        $lines[] = '';
        $lines[] = route('decisions.index').'#decision-'.$decision->id;

        return implode("\n", $lines);
    }
}
