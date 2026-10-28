<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_credits', function (Blueprint $table) {
            // Base-fee reduction (junior fee cut). Separate from
            // adjusted_registration_id, which is the surcharge reduction
            // and is unique per entry.
            $table->foreignId('fee_adjusted_registration_id')->nullable()->unique()->after('adjusted_registration_id')
                ->constrained('match_registrations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_credits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_adjusted_registration_id');
        });
    }
};
