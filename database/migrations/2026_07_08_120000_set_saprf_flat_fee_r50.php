<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * SAPRF only collects a flat R50 per shooter. The percentage-based SAPRF fee,
 * the platform operator fee and the member/lapsed surcharges are all zeroed so
 * R50 is the entire amount SAPRF takes per registration. The settings remain
 * configurable in Site Settings, so any of these can be re-enabled later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $values = [
            'saprf_fee_type' => 'fixed',
            'saprf_fee_value' => '50',
            'platform_fee_type' => 'fixed',
            'platform_fee_value' => '0',
            'non_member_surcharge' => '0',
            'lapsed_member_surcharge' => '0',
        ];

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget('saprf_settings');
    }

    public function down(): void
    {
        $values = [
            'saprf_fee_type' => 'percentage',
            'saprf_fee_value' => '5',
            'platform_fee_type' => 'percentage',
            'platform_fee_value' => '5',
            'non_member_surcharge' => '250.00',
            'lapsed_member_surcharge' => '150.00',
        ];

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget('saprf_settings');
    }
};
