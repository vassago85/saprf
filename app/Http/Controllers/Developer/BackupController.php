<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Spatie\Backup\BackupDestination\BackupDestination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        $backupName = config('backup.backup.name');
        $disks = (array) config('backup.backup.destination.disks', ['local']);

        $destinations = collect($disks)->map(function (string $disk) use ($backupName) {
            try {
                $destination = BackupDestination::create($disk, $backupName);
                $backups = $destination->backups()->map(fn ($backup) => [
                    'disk' => $disk,
                    'path' => $backup->path(),
                    'size' => $backup->sizeInBytes(),
                    'date' => $backup->date(),
                ])->all();

                return [
                    'disk' => $disk,
                    'reachable' => true,
                    'backups' => $backups,
                ];
            } catch (\Throwable $e) {
                return [
                    'disk' => $disk,
                    'reachable' => false,
                    'error' => $e->getMessage(),
                    'backups' => [],
                ];
            }
        })->all();

        $r2Configured = (bool) env('R2_ACCESS_KEY_ID') && (bool) env('R2_BUCKET');

        return view('developer.backups', compact('destinations', 'r2Configured', 'backupName'));
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            Artisan::queue('backup:run', ['--only-db' => false]);

            $this->auditLogService->log(
                $request->user(),
                'backup_triggered',
                'Backup',
                null,
                null,
                ['queued_at' => now()->toDateTimeString()],
                'Backup run queued from developer dashboard',
            );

            return redirect()->route('developer.backups.index')
                ->with('success', 'Backup queued. It will appear below once the queue worker processes it.');
        } catch (\Throwable $e) {
            return redirect()->route('developer.backups.index')
                ->with('error', 'Failed to queue backup: ' . $e->getMessage());
        }
    }

    public function download(string $disk, string $path): StreamedResponse
    {
        abort_unless(in_array($disk, (array) config('backup.backup.destination.disks', ['local']), true), 404);

        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        $filename = basename($path);

        return $storage->download($path, $filename);
    }
}
