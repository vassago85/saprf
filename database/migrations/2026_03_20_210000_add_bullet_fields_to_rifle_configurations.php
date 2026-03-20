<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->string('bullet_make', 100)->nullable()->after('bullet_description');
            $table->string('bullet_weight', 30)->nullable()->after('bullet_make');
            $table->string('bullet_type', 100)->nullable()->after('bullet_weight');
        });
    }

    public function down(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->dropColumn(['bullet_make', 'bullet_weight', 'bullet_type']);
        });
    }
};
