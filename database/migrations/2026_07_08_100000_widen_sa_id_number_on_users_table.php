<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy IDPassportNo holds SA IDs, passports and (occasionally) GUIDs.
        // varchar(13) only fits a bare SA ID; widen it so real passports don't
        // overflow. Junk values are still stripped by the importer.
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'sa_id_number')) {
                $table->string('sa_id_number', 32)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'sa_id_number')) {
                $table->string('sa_id_number', 13)->nullable()->change();
            }
        });
    }
};
