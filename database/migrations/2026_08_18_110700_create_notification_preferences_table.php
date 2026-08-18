<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user opt-outs for non-mandatory announcement categories, plus
     * a device-wide push toggle. Policy change + Urgent categories
     * ignore `muted_email_categories` and always attempt to send.
     *
     * A missing row means "default preferences" (all categories on;
     * push follows subscription state).
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()
                ->constrained('users')->cascadeOnDelete();
            $table->json('muted_email_categories')->nullable();
            $table->boolean('push_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
