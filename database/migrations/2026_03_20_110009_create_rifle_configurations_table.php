<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rifle_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nickname');
            $table->foreignId('firearm_make_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('firearm_model_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('firearm_calibre_id')->nullable()->constrained()->nullOnDelete();
            $table->string('optic_description')->nullable();
            $table->string('bullet_description')->nullable();
            $table->string('barrel_length')->nullable();
            $table->string('twist_rate')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rifle_configurations');
    }
};
