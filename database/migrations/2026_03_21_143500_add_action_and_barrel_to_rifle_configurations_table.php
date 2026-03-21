<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->string('action_description')->nullable()->after('firearm_calibre_id');
            $table->string('barrel_description')->nullable()->after('action_description');
        });
    }

    public function down(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->dropColumn(['action_description', 'barrel_description']);
        });
    }
};
