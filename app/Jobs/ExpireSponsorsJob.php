<?php

namespace App\Jobs;

use App\Models\Sponsor;
use App\Services\AuditLogService;
use App\Services\SponsorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExpireSponsorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AuditLogService $auditLogService, SponsorService $sponsorService): void
    {
        $expired = Sponsor::expired()->with('tier')->get();

        if ($expired->isEmpty()) {
            return;
        }

        foreach ($expired as $sponsor) {
            $sponsor->update(['is_active' => false]);

            $auditLogService->log(
                null,
                'sponsor_auto_expired',
                'Sponsor',
                $sponsor->id,
                ['is_active' => true, 'expires_at' => $sponsor->expires_at->toDateString()],
                ['is_active' => false],
                "Sponsor '{$sponsor->name}' ({$sponsor->tier->name}) auto-expired",
            );
        }

        $sponsorService->clearCache();
    }
}
