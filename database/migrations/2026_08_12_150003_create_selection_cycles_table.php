<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('series');
            $table->string('season');
            $table->string('championship_name');
            $table->date('qualifying_period_start');
            $table->date('qualifying_period_end');
            $table->dateTime('declaration_deadline');
            $table->date('results_freeze');
            $table->date('panel_lock_date')->nullable();
            $table->date('deliberation_start')->nullable();
            $table->date('deliberation_end')->nullable();
            $table->date('publication_date')->nullable();
            $table->unsignedBigInteger('active_policy_version_id')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['series', 'season']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selection_cycles');
    }
};
