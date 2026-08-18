<?php

namespace App\Jobs;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Services\Announcements\AnnouncementPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Freeze the recipient snapshot for an announcement.
 *
 * Runs on the queue because a big broadcast (all active members ~5k+)
 * shouldn't hold the HTTP request. Once recipients are frozen, this
 * job dispatches DispatchAnnouncementJob for the per-channel fan-out.
 *
 * Idempotent: recipient rows are `firstOrCreate` keyed on
 * (announcement_id, user_id) so a retry doesn't double-send anything.
 */
class ResolveAudienceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $announcementId,
    ) {}

    public function handle(AnnouncementPublisher $publisher): void
    {
        $announcement = Announcement::query()->find($this->announcementId);

        if (! $announcement) {
            Log::warning('ResolveAudienceJob: announcement not found', ['id' => $this->announcementId]);
            return;
        }

        if ($announcement->status === AnnouncementStatus::Cancelled) {
            Log::info('ResolveAudienceJob: announcement was cancelled before resolve', ['id' => $announcement->id]);
            return;
        }

        $count = $publisher->freezeRecipients($announcement);

        Log::info('ResolveAudienceJob: recipients frozen', [
            'announcement_id' => $announcement->id,
            'count' => $count,
        ]);

        DispatchAnnouncementJob::dispatch($announcement->id);
    }
}
