<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a developer is impersonating a member, audit_logs.user_id is the
     * developer (the real actor). impersonated_user_id is the member whose
     * identity they had assumed at the time of the write. Null on every
     * ordinary (non-impersonated) row.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('impersonated_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonated_user_id');
        });
    }
};
