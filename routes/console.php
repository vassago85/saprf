<?php

use App\Jobs\AutoCompletePastMatchesJob;
use App\Jobs\DispatchScheduledAnnouncementsJob;
use App\Jobs\ExpireMembershipsJob;
use App\Jobs\ExpireSponsorsJob;
use App\Jobs\NotifyRegistrationClosedPendingJob;
use App\Jobs\ResolvePendingScoresJob;
use App\Jobs\SyncImpactLiveScoresJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ResolvePendingScoresJob)->dailyAt('01:00');
Schedule::job(new ExpireSponsorsJob)->dailyAt('00:15');
Schedule::job(new ExpireMembershipsJob)->dailyAt('02:00');
// Close out any match whose last day has passed. Runs after ExpireMembershipsJob
// so a lapsed member's status is settled before their last match's scores are
// finalised (affects the counts_for_season flag on shooter logs).
Schedule::job(new AutoCompletePastMatchesJob)->dailyAt('02:15');

// After registration_close_date has passed: email MD the pending/unpaid
// list and each of those shooters with pay-or-withdraw options.
Schedule::job(new NotifyRegistrationClosedPendingJob)->dailyAt('06:00');

// Pick up any announcements the composer scheduled for a future time
// and push them into the resolve/dispatch pipeline. Cheap query, so we
// tick every minute so scheduled sends land within one minute of `send_at`.
Schedule::job(new DispatchScheduledAnnouncementsJob)->everyMinute();

// Impact live scoreboard. Only matches with the switch on, an Impact id,
// and a date window that includes today are touched. The job itself
// no-ops for anything else.
Schedule::job(new SyncImpactLiveScoresJob)
    ->everyFiveMinutes()
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping();

Schedule::command('backup:clean')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('03:00')->withoutOverlapping();

// Invoice SAPRF for the previous month's paid entries. Stays quiet until a
// developer turns it on in Site Settings.
Schedule::command('saprf:invoice-charsley')
    ->monthlyOn(1, '07:00')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping();
