<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedBigInteger('impact_match_id')->nullable()->after('everyone_counts');
            $table->boolean('impact_live_sync')->default(false)->after('impact_match_id');
            $table->timestamp('impact_live_synced_at')->nullable()->after('impact_live_sync');
            $table->timestamp('impact_live_ended_at')->nullable()->after('impact_live_synced_at');
            $table->string('impact_live_error', 500)->nullable()->after('impact_live_ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn([
                'impact_match_id',
                'impact_live_sync',
                'impact_live_synced_at',
                'impact_live_ended_at',
                'impact_live_error',
            ]);
        });
    }
};
