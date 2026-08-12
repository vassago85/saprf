<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selection_athlete_id')
                ->constrained('selection_athletes')
                ->cascadeOnDelete();
            $table->string('waived_rule_id');
            $table->text('request_text')->nullable();
            $table->string('request_file_path')->nullable();
            $table->text('response_text')->nullable();
            $table->string('outcome')->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            $table->index(['selection_athlete_id', 'waived_rule_id']);
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('selection_waivers');
    }
};
