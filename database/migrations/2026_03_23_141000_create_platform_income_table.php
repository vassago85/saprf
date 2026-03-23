<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_income', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50);
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->date('income_date');
            $table->string('source')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('category');
            $table->index('income_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_income');
    }
};
