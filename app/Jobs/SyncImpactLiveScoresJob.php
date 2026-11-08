<?php

namespace App\Jobs;

use App\Models\MatchEvent;
use App\Services\ImpactScoring\ImpactLiveSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pull Impact live scores for matches happening today. Scheduled every
 * five minutes. One match failing does not stop the others.
 */
class SyncImpactLiveScoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ImpactLiveSyncService $sync): void
    {
        MatchEvent::query()
            ->dueForImpactLiveSync()
            ->orderBy('id')
            ->each(function (MatchEvent $match) use ($sync): void {
                try {
                    $sync->sync($match);
                } catch (Throwable $e) {
                    report($e);
                    $match->forceFill([
                        'impact_live_error' => Str::limit($e->getMessage(), 500),
                    ])->save();
                }
            });
    }
}
