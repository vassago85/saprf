<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selection_cycle_id')->constrained('selection_cycles')->cascadeOnDelete();
            $table->string('version');
            $table->string('source_path')->nullable();
            $table->string('source_hash', 64);
            $table->json('spec_json');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('imported_at');
            $table->timestamps();

            $table->unique(['selection_cycle_id', 'version']);
        });

        Schema::table('selection_cycles', function (Blueprint $table) {
            $table->foreign('active_policy_version_id')
                ->references('id')
                ->on('selection_policies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('selection_cycles', function (Blueprint $table) {
            $table->dropForeign(['active_policy_version_id']);
        });

        Schema::dropIfExists('selection_policies');
    }
};
