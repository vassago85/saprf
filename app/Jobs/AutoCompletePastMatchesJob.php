<?php

namespace App\Jobs;

use App\Models\MatchEvent;
use App\Services\AuditLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Move any match whose last scheduled day has already passed into the
 * `completed` state, even if the match director never uploaded scores or
 * manually clicked "Complete match". This keeps the public events list
 * accurate: once a match is over, it disappears from the Upcoming tab and
 * shows up under Results (with an empty podium if no scores exist yet).
 *
 * Scheduled daily from routes/console.php. Complements the auto-complete
 * hook inside ScoreImportService::importCsv() — the import path closes
 * matches as soon as scores arrive; this job is the safety net for
 * matches whose scores never do.
 */
class AutoCompletePastMatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AuditLogService $audit): void
    {
        $today = now()->startOfDay();

        // A match is "in the past" when its effective end date (match_end_date
        // if set, else match_date) is strictly before today — i.e. yesterday or
        // earlier. Matches still running today are left alone so an MD who
        // reports live on match day doesn't have registration close under
        // them mid-event.
        $matches = MatchEvent::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where(function ($q) use ($today) {
                $q->where(function ($q2) use ($today) {
                    $q2->whereNull('match_end_date')
                        ->where('match_date', '<', $today);
                })->orWhere(function ($q2) use ($today) {
                    $q2->whereNotNull('match_end_date')
                        ->where('match_end_date', '<', $today);
                });
            })
            ->get();

        foreach ($matches as $match) {
            $previousStatus = $match->status;
            $match->update(['status' => 'completed']);

            $audit->log(
                null,
                'match.auto_completed_past_end_date',
                'MatchEvent',
                $match->id,
                ['status' => $previousStatus],
                [
                    'status' => 'completed',
                    'ended_on' => ($match->match_end_date ?? $match->match_date)?->toDateString(),
                ],
                "Match '{$match->name}' auto-completed (ended " . ($match->match_end_date ?? $match->match_date)?->format('d M Y') . ')',
            );
        }
    }
}
