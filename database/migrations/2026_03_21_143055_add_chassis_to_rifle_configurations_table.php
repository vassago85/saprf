<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->string('chassis_description')->nullable()->after('optic_description');
        });
    }

    public function down(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->dropColumn('chassis_description');
        });
    }
};
