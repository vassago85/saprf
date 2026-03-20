<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ammo_loads', function (Blueprint $table) {
            $table->foreignId('rifle_configuration_id')->nullable()->after('user_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ammo_loads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rifle_configuration_id');
        });
    }
};
