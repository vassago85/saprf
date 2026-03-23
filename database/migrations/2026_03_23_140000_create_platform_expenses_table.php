<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50);
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->string('reference')->nullable();
            $table->string('vendor')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('category');
            $table->index('expense_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_expenses');
    }
};
