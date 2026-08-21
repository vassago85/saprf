<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barrels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rifle_configuration_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('label', 120);
            $table->string('chambering', 60)->nullable();
            $table->string('maker', 80)->nullable();
            $table->unsignedSmallInteger('length_mm')->nullable();
            $table->string('twist_rate', 20)->nullable();
            $table->unsignedInteger('round_count')->default(0);
            $table->date('installed_on')->nullable();
            $table->date('retired_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'retired_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barrels');
    }
};
