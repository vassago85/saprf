<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->string('optic_make')->nullable()->after('chassis_description');
            $table->string('optic_model')->nullable()->after('optic_make');
        });
    }

    public function down(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->dropColumn(['optic_make', 'optic_model']);
        });
    }
};
