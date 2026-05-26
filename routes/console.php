<?php

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

Schedule::command('backup:clean')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('03:00')->withoutOverlapping();
