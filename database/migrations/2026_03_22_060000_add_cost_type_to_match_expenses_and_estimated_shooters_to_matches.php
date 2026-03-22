<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_expenses', function (Blueprint $table) {
            $table->string('cost_type', 20)->default('fixed')->after('amount');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedSmallInteger('estimated_shooters')->nullable()->after('max_competitors');
        });
    }

    public function down(): void
    {
        Schema::table('match_expenses', function (Blueprint $table) {
            $table->dropColumn('cost_type');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('estimated_shooters');
        });
    }
};
