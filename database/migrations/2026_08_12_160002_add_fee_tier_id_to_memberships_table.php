<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('fee_tier_id')
                ->nullable()
                ->after('membership_type')
                ->constrained('membership_fee_tiers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_tier_id');
        });
    }
};
