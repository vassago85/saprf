<?php

use App\Jobs\AutoCompletePastMatchesJob;
use App\Jobs\DispatchScheduledAnnouncementsJob;
use App\Jobs\ExpireMembershipsJob;
use App\Jobs\ExpireSponsorsJob;
use App\Jobs\ResolvePendingScoresJob;
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

// Pick up any announcements the composer scheduled for a future time
// and push them into the resolve/dispatch pipeline. Cheap query, so we
// tick every minute so scheduled sends land within one minute of `send_at`.
Schedule::job(new DispatchScheduledAnnouncementsJob)->everyMinute();

Schedule::command('backup:clean')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('03:00')->withoutOverlapping();
