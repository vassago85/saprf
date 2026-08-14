<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-match SAPRF and platform fee overrides. When null, the match inherits
 * the global fee settings from Site Settings. When set, they override — used
 * for imported matches (where nothing was collected through the platform, so
 * the platform fee should be R0) and any other one-off cases the federation
 * needs (exco/developer can set these in the match edit form).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->string('platform_fee_type', 20)->nullable()->after('junior_fee');
            $table->decimal('platform_fee_value', 8, 2)->nullable()->after('platform_fee_type');
            $table->string('saprf_fee_type', 20)->nullable()->after('platform_fee_value');
            $table->decimal('saprf_fee_value', 8, 2)->nullable()->after('saprf_fee_type');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['platform_fee_type', 'platform_fee_value', 'saprf_fee_type', 'saprf_fee_value']);
        });
    }
};
