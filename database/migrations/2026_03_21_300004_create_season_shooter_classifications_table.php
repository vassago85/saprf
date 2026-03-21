<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_shooter_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('season', 10);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('discipline', 10);
            $table->date('classification_date');
            $table->unsignedTinyInteger('age_on_classification_date')->nullable();
            $table->foreignId('effective_division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->boolean('is_locked')->default(true);
            $table->boolean('override_applied')->default(false);
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->unique(['season', 'user_id', 'discipline'], 'season_user_discipline_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_shooter_classifications');
    }
};
