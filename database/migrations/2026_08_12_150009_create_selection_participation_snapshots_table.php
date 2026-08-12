<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_participation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selection_athlete_id')
                ->constrained('selection_athletes')
                ->cascadeOnDelete();
            $table->unsignedInteger('provincial_1d_count')->default(0);
            $table->unsignedInteger('national_2d_count')->default(0);
            $table->unsignedInteger('international_2d_count')->default(0);
            $table->unsignedInteger('out_of_home_province_2d_count')->default(0);
            $table->boolean('sa_champs_shot')->default(false);
            $table->json('counted_score_ids')->nullable();
            $table->dateTime('computed_at');
            $table->unsignedBigInteger('computed_against_policy_id')->nullable();
            $table->foreign('computed_against_policy_id', 'sel_snap_policy_fk')
                ->references('id')
                ->on('selection_policies')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique('selection_athlete_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selection_participation_snapshots');
    }
};
