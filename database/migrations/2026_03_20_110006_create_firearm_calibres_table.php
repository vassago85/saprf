<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firearm_calibres', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->default('rifle');
            $table->string('family')->nullable();
            $table->decimal('bullet_diameter', 6, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firearm_calibres');
    }
};
