<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_rule_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selection_athlete_id')
                ->constrained('selection_athletes')
                ->cascadeOnDelete();
            $table->string('rule_id');
            $table->string('outcome');
            $table->json('detail')->nullable();
            $table->string('policy_version');
            $table->dateTime('evaluated_at');
            $table->timestamps();

            $table->index(['selection_athlete_id', 'rule_id']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selection_rule_evaluations');
    }
};
