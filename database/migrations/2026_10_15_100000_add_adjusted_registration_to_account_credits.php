<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_credits', function (Blueprint $table) {
            // Surcharge adjustment: the paid entry whose surcharge was partly credited back.
            // Kept apart from issued_for_registration_id, which marks an entry as fully credited.
            $table->foreignId('adjusted_registration_id')->nullable()->unique()->after('applied_to_registration_id')
                ->constrained('match_registrations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_credits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjusted_registration_id');
        });
    }
};
