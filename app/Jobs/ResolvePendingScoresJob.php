<?php

namespace App\Jobs;

use App\Services\ScoreValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResolvePendingScoresJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    public function handle(ScoreValidationService $scoreValidationService): void
    {
        $scoreValidationService->resolvePendingScores();
    }
}
