<?php

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
