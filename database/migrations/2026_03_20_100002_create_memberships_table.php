<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('saprf_number')->unique();
            $table->string('membership_type')->default('free');
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->date('start_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->index(['status', 'payment_status']);
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
