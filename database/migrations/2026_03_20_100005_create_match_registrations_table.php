<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('shooter_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('membership_fee_category');
            $table->decimal('fee_amount', 10, 2);
            $table->text('fee_override_reason')->nullable();
            $table->string('payment_status')->default('pending');
            $table->string('registration_status')->default('pending');
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();

            $table->index(['match_id', 'registration_status']);
            $table->index(['match_id', 'membership_fee_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_registrations');
    }
};
