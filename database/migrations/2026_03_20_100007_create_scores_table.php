<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('score_import_id')->nullable()->constrained('score_imports')->nullOnDelete();
            $table->string('shooter_name');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('raw_score', 10, 3)->default(0);
            $table->unsignedInteger('placement')->nullable();
            $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->unsignedInteger('total_possible_shots')->nullable();
            $table->decimal('hit_percentage', 6, 3)->nullable();
            $table->decimal('normalized_score', 8, 4)->nullable();
            $table->unsignedInteger('overall_rank')->nullable();
            $table->unsignedInteger('division_rank')->nullable();
            $table->boolean('is_member')->default(false);
            $table->string('status')->default('pending');
            $table->text('validation_reason')->nullable();
            $table->date('match_date');
            $table->json('raw_meta')->nullable();
            $table->boolean('counts_for_log')->default(true);
            $table->boolean('counts_for_season')->default(true);
            $table->timestamps();

            $table->index(['match_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
