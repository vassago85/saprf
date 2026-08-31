<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Grace period cut-off for match-registration fees.
 *
 * Any registration with `registered_at` before this date has its `saprf_fee`
 * and `platform_fee` zeroed and rolled into `md_net_amount`. The
 * RegistrationPricingService applies the waiver at registration time; the
 * `saprf:waive-fees-before-date` command backfills existing rows. Move the
 * date forward in Site Settings if the grace period needs to be extended.
 *
 * Initial value is 2026-09-01 — the new SAPRF platform fee schedule was not
 * communicated to match directors in time for August, so August 2026
 * transactions are non-billable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Setting::updateOrCreate(
            ['key' => 'billing_start_date'],
            [
                'value' => '2026-09-01',
                'description' => 'ISO date. Registrations with registered_at before this date have SAPRF + platform fees waived.',
            ],
        );

        Cache::forget('saprf_settings');
    }

    public function down(): void
    {
        Setting::where('key', 'billing_start_date')->delete();

        Cache::forget('saprf_settings');
    }
};
