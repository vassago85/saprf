<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selection_athlete_id')
                ->constrained('selection_athletes')
                ->cascadeOnDelete();
            $table->dateTime('lodged_at');
            $table->text('reason');
            $table->dateTime('fee_paid_at')->nullable();
            $table->decimal('fee_amount', 10, 2)->default(5000.00);
            $table->string('outcome')->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('refund_issued_at')->nullable();
            $table->timestamps();

            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selection_appeals');
    }
};
