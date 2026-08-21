<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // starting_round_count is what the barrel had on it before the platform
        // started tracking shots. round_count remains the cached lifetime total
        // (starting + sum of entries) so existing UI/ladder queries don't need
        // to change.
        Schema::table('barrels', function (Blueprint $table) {
            $table->unsignedInteger('starting_round_count')->default(0)->after('twist_rate');
        });

        DB::statement('UPDATE barrels SET starting_round_count = round_count');

        Schema::create('barrel_shot_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barrel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('fired_on');
            $table->unsignedSmallInteger('shot_count');
            $table->enum('type', ['practice', 'non_saprf']);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['barrel_id', 'fired_on']);
            $table->index(['user_id', 'fired_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barrel_shot_entries');

        Schema::table('barrels', function (Blueprint $table) {
            $table->dropColumn('starting_round_count');
        });
    }
};
