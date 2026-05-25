<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_managed_account')->default(false)->after('parent_id');

            // Handover invite (when parent transfers control to junior)
            $table->string('handover_email')->nullable()->after('email_otp_expires_at');
            $table->string('handover_token', 100)->nullable()->after('handover_email');
            $table->timestamp('handover_expires_at')->nullable()->after('handover_token');

            $table->index('parent_id');
            $table->index('handover_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn([
                'is_managed_account',
                'handover_email',
                'handover_token',
                'handover_expires_at',
            ]);
        });
    }
};
