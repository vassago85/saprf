<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provincial_committees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('position');
            $table->boolean('is_active')->default(true);
            $table->date('appointed_at')->nullable();
            $table->timestamps();

            $table->unique(['province_id', 'user_id']);
            $table->index(['province_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provincial_committees');
    }
};
