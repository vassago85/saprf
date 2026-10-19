<?php

namespace App\Jobs;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Notifications\RegistrationClosedPendingMdNotification;
use App\Notifications\RegistrationClosedPendingShooterNotification;
use App\Services\AuditLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * When a match's registration_close_date has passed, email:
 *   - the match director with the list of pending / unpaid entrants
 *   - each of those shooters with pay-or-withdraw options
 *
 * Idempotent via matches.registration_close_notified_at.
 */
class NotifyRegistrationClosedPendingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AuditLogService $audit): void
    {
        $today = now()->startOfDay();

        $matches = MatchEvent::query()
            ->with(['creator'])
            ->whereNotNull('registration_close_date')
            ->whereNull('registration_close_notified_at')
            ->where('registration_close_date', '<', $today)
            ->whereNotIn('status', ['cancelled'])
            ->get();

        foreach ($matches as $match) {
            $pending = MatchRegistration::query()
                ->with(['user:id,name,email', 'division:id,name'])
                ->where('match_id', $match->id)
                ->where(function ($q) {
                    $q->whereNull('registration_status')
                        ->orWhereNotIn('registration_status', ['cancelled']);
                })
                ->where(function ($q) {
                    $q->where('registration_status', 'pending')
                        ->orWhere(function ($q2) {
                            $q2->where('fee_amount', '>', 0)
                                ->whereNotIn('payment_status', ['paid', 'waived']);
                        });
                })
                ->orderBy('shooter_name')
                ->get();

            // Always stamp notified so we don't re-scan forever when nobody is pending.
            $match->forceFill(['registration_close_notified_at' => now()])->save();

            if ($pending->isEmpty()) {
                $audit->log(
                    null,
                    'match.registration_close.notified_empty',
                    'MatchEvent',
                    $match->id,
                    null,
                    ['pending_count' => 0],
                    "Registration closed for '{$match->name}' — no pending/unpaid entrants.",
                );

                continue;
            }

            foreach ($pending as $registration) {
                $shooter = $registration->user;
                if ($shooter?->email) {
                    $shooter->notify(new RegistrationClosedPendingShooterNotification($registration));
                }
            }

            if ($match->creator?->email) {
                $match->creator->notify(new RegistrationClosedPendingMdNotification($match, $pending));
            }

            $audit->log(
                null,
                'match.registration_close.notified',
                'MatchEvent',
                $match->id,
                null,
                [
                    'pending_count' => $pending->count(),
                    'registration_ids' => $pending->pluck('id')->all(),
                ],
                "Registration closed for '{$match->name}' — notified MD + {$pending->count()} pending/unpaid shooter(s).",
            );
        }
    }
}
