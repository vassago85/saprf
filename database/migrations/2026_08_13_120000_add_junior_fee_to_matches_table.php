<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Optional discounted entry fee charged for Junior-division entries.
            // Null = no junior discount (juniors pay the normal entry fee).
            $table->decimal('junior_fee', 10, 2)->nullable()->after('lapsed_member_fee');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('junior_fee');
        });
    }
};
