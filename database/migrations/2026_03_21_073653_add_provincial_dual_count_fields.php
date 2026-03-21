<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('also_counts_for_provincial')->default(false)->after('category_awards_enabled');
            $table->text('provincial_stage_columns')->nullable()->after('also_counts_for_provincial');
        });

        Schema::table('scores', function (Blueprint $table) {
            $table->decimal('provincial_raw_score', 10, 3)->nullable()->after('raw_score');
            $table->decimal('provincial_normalized_score', 8, 4)->nullable()->after('normalized_score');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['also_counts_for_provincial', 'provincial_stage_columns']);
        });

        Schema::table('scores', function (Blueprint $table) {
            $table->dropColumn(['provincial_raw_score', 'provincial_normalized_score']);
        });
    }
};
