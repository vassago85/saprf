<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firearm_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firearm_make_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['firearm_make_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firearm_models');
    }
};
