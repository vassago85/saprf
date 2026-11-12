<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('decision_tallies', 'neutrals')) {
            return;
        }

        Schema::table('decision_tallies', function (Blueprint $table) {
            $table->unsignedInteger('neutrals')->default(0)->after('dislikes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('decision_tallies', 'neutrals')) {
            return;
        }

        Schema::table('decision_tallies', function (Blueprint $table) {
            $table->dropColumn('neutrals');
        });
    }
};
