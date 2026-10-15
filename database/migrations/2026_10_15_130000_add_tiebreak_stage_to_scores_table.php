<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            // Championship timed tiebreak stage (2027 national series rules):
            // more impacts wins, equal impacts go to the faster time.
            $table->unsignedSmallInteger('tiebreak_impacts')->nullable()->after('day2_raw_score');
            $table->decimal('tiebreak_time_seconds', 8, 3)->nullable()->after('tiebreak_impacts');
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropColumn(['tiebreak_impacts', 'tiebreak_time_seconds']);
        });
    }
};
