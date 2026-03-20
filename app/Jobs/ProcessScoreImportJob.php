<?php

namespace App\Jobs;

use App\Models\ScoreImport;
use App\Services\ScoreImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessScoreImportJob implements ShouldQueue
{
    use Queueable, Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(
        public int $scoreImportId,
        public string $storagePath,
    ) {}

    public function handle(ScoreImportService $scoreImportService): void
    {
        $import = ScoreImport::query()->find($this->scoreImportId);

        if ($import === null) {
            return;
        }

        try {
            $scoreImportService->importCsv($import, $this->storagePath);
            $import->update(['import_status' => 'completed']);
        } catch (Throwable) {
            $import->update(['import_status' => 'failed']);
        }
    }
}
