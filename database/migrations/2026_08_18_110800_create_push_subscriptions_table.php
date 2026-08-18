<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Web Push subscriptions produced by `PushManager.subscribe()` in
     * the browser. One row per (user, device+browser). The `endpoint`
     * is globally unique per push service — we key on it so re-registering
     * the same device replaces the existing row rather than creating
     * duplicates.
     *
     * Pruned automatically on 404/410 responses from the push service
     * (WebPushChannel handles this on send).
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint', 500)->unique();
            $table->string('p256dh', 200);
            $table->string('auth', 100);
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
