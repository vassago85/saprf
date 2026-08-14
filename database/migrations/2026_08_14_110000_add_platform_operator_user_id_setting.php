<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * The monthly platform-fee payout needs a payee. Seed the key as empty on
 * existing installs so the Site Settings UI can render its selector, then the
 * owner picks the operator once and platform payouts can be generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'platform_operator_user_id'],
            ['value' => '', 'description' => 'User ID who receives monthly platform-fee payouts'],
        );

        Cache::forget('saprf_settings');
    }

    public function down(): void
    {
        Setting::where('key', 'platform_operator_user_id')->delete();
        Cache::forget('saprf_settings');
    }
};
