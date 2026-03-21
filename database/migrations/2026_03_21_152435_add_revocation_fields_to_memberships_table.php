<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('expiry_date');
            $table->string('revocation_reason', 1000)->nullable()->after('revoked_at');
            $table->foreignId('revoked_by')->nullable()->after('revocation_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['revoked_by']);
            $table->dropColumn(['revoked_at', 'revocation_reason', 'revoked_by']);
        });
    }
};
