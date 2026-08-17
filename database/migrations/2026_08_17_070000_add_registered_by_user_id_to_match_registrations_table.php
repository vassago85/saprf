<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The user who created the registration on behalf of the shooter.
     * Null for classic self-entry, the parent for a managed-family entry,
     * and the sponsor for a sponsor-driven entry.
     */
    public function up(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            $table->foreignId('registered_by_user_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_by_user_id');
        });
    }
};
