<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Walk-in shooters are people on the score sheet who did NOT enter
     * through the site. The MD collected their entry fee at the range,
     * so no PayFast transaction exists, but SAPRF and platform still owe
     * their cut and the shooter still needs an audit trail to appear in
     * standings.
     *
     * Columns:
     *   - registration_source: 'online' (default, existing rows) vs 'walk_in'.
     *   - walk_in_note: MD's free-text reason ("Paid R500 cash, receipt #123").
     *   - walk_in_confirmed_by / walk_in_confirmed_at: which admin/MD confirmed
     *     the walk-in and when — exco sees this on the audit report.
     */
    public function up(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            $table->string('registration_source', 16)
                ->default('online')
                ->after('registered_by_user_id');

            $table->text('walk_in_note')->nullable()->after('cancellation_reason');

            $table->foreignId('walk_in_confirmed_by')
                ->nullable()
                ->after('walk_in_note')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('walk_in_confirmed_at')->nullable()->after('walk_in_confirmed_by');

            $table->index(['match_id', 'registration_source'], 'match_reg_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            $table->dropIndex('match_reg_source_idx');
            $table->dropConstrainedForeignId('walk_in_confirmed_by');
            $table->dropColumn(['registration_source', 'walk_in_note', 'walk_in_confirmed_at']);
        });
    }
};
