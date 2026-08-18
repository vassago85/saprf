<?php

namespace App\Jobs;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Every minute, scoop up any `scheduled` announcements whose `send_at`
 * has passed and kick them into the resolve/dispatch pipeline. Split
 * from the send pipeline itself so a stuck notification worker doesn't
 * stop new scheduled sends from being picked up.
 */
class DispatchScheduledAnnouncementsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $due = Announcement::query()
            ->where('status', AnnouncementStatus::Scheduled)
            ->whereNotNull('send_at')
            ->where('send_at', '<=', now())
            ->pluck('id');

        foreach ($due as $id) {
            Log::info('DispatchScheduledAnnouncementsJob: dispatching scheduled announcement', ['id' => $id]);
            ResolveAudienceJob::dispatch((int) $id);
        }
    }
}
