<?php

namespace App\Jobs;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Fan out a resolved announcement into per-channel chunk jobs.
 *
 * We chunk at 50 recipients per job because:
 *   - RateLimited('mail') is 5/sec / 300/min — a 50-message chunk fits
 *     comfortably in that budget
 *   - if a chunk fails, only 50 recipients need retry, not the entire
 *     broadcast
 *
 * The `database` channel is not chunked — its "delivery" is just the
 * recipient row existing, which ResolveAudienceJob already produces.
 */
class DispatchAnnouncementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public const CHUNK_SIZE = 50;

    public function __construct(
        public readonly int $announcementId,
    ) {}

    public function handle(): void
    {
        $announcement = Announcement::query()->findOrFail($this->announcementId);

        foreach ([DeliveryChannel::Mail, DeliveryChannel::WebPush] as $channel) {
            $recipientIds = DB::table('announcement_deliveries')
                ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
                ->where('announcement_recipients.announcement_id', $announcement->id)
                ->where('announcement_deliveries.channel', $channel->value)
                ->where('announcement_deliveries.status', DeliveryStatus::Queued->value)
                ->pluck('announcement_recipients.user_id');

            foreach ($recipientIds->chunk(self::CHUNK_SIZE) as $chunk) {
                SendAnnouncementChunkJob::dispatch(
                    $announcement->id,
                    $channel->value,
                    $chunk->values()->all(),
                );
            }
        }
    }
}
