<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_athletes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selection_cycle_id')->constrained('selection_cycles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('claimed_division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->string('state')->default('registered');
            $table->text('manual_eligibility_notes')->nullable();
            $table->dateTime('last_evaluated_at')->nullable();
            $table->unsignedBigInteger('evaluated_against_policy_id')->nullable();
            $table->foreign('evaluated_against_policy_id', 'sel_athletes_policy_fk')
                ->references('id')
                ->on('selection_policies')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['selection_cycle_id', 'user_id']);
            $table->index('state');
            $table->index('claimed_division_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selection_athletes');
    }
};
